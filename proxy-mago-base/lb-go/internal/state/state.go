// Package state é o estado vivo compartilhado com o cérebro.
//
// O LAYOUT DE CHAVES É CONTRATO (app/StateStore.php):
//
//	cdnv:sess:<session_key>  string JSON + TTL   sessão viva
//	cdnv:user:<username>     set                índice de sessões do usuário
//	cdnv:lb:<lb_id>          string JSON + TTL   presence/heartbeat do nó
//
// Regra inegociável: Redis fora do ar NÃO derruba player. Aqui não há fallback
// para SQLite (o músculo não tem banco); a degradação é explícita: enforcement
// que depende de contador é liberado, e o cérebro recebe a contagem pelos
// eventos.
package state

import (
	"bufio"
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"strconv"
	"strings"
	"sync"
	"time"
)

const NS = "cdnv:"

const userSetTTL = 86400

type Store struct {
	addr    string
	pass    string
	db      int
	timeout time.Duration

	mu        sync.Mutex
	conn      net.Conn
	rd        *bufio.Reader
	down      bool
	downUntil time.Time
	lastErr   string
}

func New(host string, port int, pass string, db int) *Store {
	return &Store{
		addr:    net.JoinHostPort(host, strconv.Itoa(port)),
		pass:    pass,
		db:      db,
		timeout: 1500 * time.Millisecond,
	}
}

// Health expõe o mesmo formato conceitual de StateStore::health().
func (s *Store) Health() map[string]any {
	s.mu.Lock()
	defer s.mu.Unlock()
	return map[string]any{
		"driver":   map[bool]string{true: "degraded", false: "redis"}[s.down],
		"degraded": s.down,
		"reason":   s.lastErr,
	}
}

func (s *Store) Degraded() bool {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.down
}

func (s *Store) dial() error {
	c, err := net.DialTimeout("tcp", s.addr, s.timeout)
	if err != nil {
		return err
	}
	s.conn = c
	s.rd = bufio.NewReader(c)
	if s.pass != "" {
		if _, err := s.raw([]string{"AUTH", s.pass}); err != nil {
			s.drop(err)
			return err
		}
	}
	if s.db != 0 {
		if _, err := s.raw([]string{"SELECT", strconv.Itoa(s.db)}); err != nil {
			s.drop(err)
			return err
		}
	}
	return nil
}

func (s *Store) drop(err error) {
	if s.conn != nil {
		_ = s.conn.Close()
	}
	s.conn = nil
	s.rd = nil
	s.down = true
	s.downUntil = time.Now().Add(5 * time.Second)
	if err != nil {
		s.lastErr = err.Error()
	}
}

// do executa comandos com reconexão preguiçosa e circuit breaker curto.
func (s *Store) do(args ...[]string) ([]any, error) {
	s.mu.Lock()
	defer s.mu.Unlock()

	if s.down && time.Now().Before(s.downUntil) {
		return nil, errors.New("redis em backoff: " + s.lastErr)
	}
	if s.conn == nil {
		if err := s.dial(); err != nil {
			s.drop(err)
			return nil, err
		}
	}

	out := make([]any, 0, len(args))
	for _, cmd := range args {
		v, err := s.raw(cmd)
		if err != nil {
			s.drop(err)
			return nil, err
		}
		out = append(out, v)
	}
	s.down = false
	s.lastErr = ""
	return out, nil
}

// raw fala RESP2 na mão — zero dependência externa, igual app/RedisClient.php.
func (s *Store) raw(cmd []string) (any, error) {
	var b strings.Builder
	fmt.Fprintf(&b, "*%d\r\n", len(cmd))
	for _, a := range cmd {
		fmt.Fprintf(&b, "$%d\r\n%s\r\n", len(a), a)
	}
	_ = s.conn.SetDeadline(time.Now().Add(s.timeout))
	if _, err := s.conn.Write([]byte(b.String())); err != nil {
		return nil, err
	}
	return readReply(s.rd)
}

func readReply(rd *bufio.Reader) (any, error) {
	line, err := rd.ReadString('\n')
	if err != nil {
		return nil, err
	}
	line = strings.TrimRight(line, "\r\n")
	if line == "" {
		return nil, errors.New("resposta vazia do redis")
	}
	switch line[0] {
	case '+':
		return line[1:], nil
	case '-':
		return nil, errors.New("redis: " + line[1:])
	case ':':
		return strconv.ParseInt(line[1:], 10, 64)
	case '$':
		n, err := strconv.Atoi(line[1:])
		if err != nil {
			return nil, err
		}
		if n < 0 {
			return nil, nil
		}
		buf := make([]byte, n+2)
		if _, err := readFull(rd, buf); err != nil {
			return nil, err
		}
		return string(buf[:n]), nil
	case '*':
		n, err := strconv.Atoi(line[1:])
		if err != nil {
			return nil, err
		}
		if n < 0 {
			return nil, nil
		}
		items := make([]any, 0, n)
		for i := 0; i < n; i++ {
			v, err := readReply(rd)
			if err != nil {
				return nil, err
			}
			items = append(items, v)
		}
		return items, nil
	}
	return nil, errors.New("prefixo RESP desconhecido: " + line[:1])
}

func readFull(rd *bufio.Reader, buf []byte) (int, error) {
	total := 0
	for total < len(buf) {
		n, err := rd.Read(buf[total:])
		total += n
		if err != nil {
			return total, err
		}
	}
	return total, nil
}

// SessionTouch cria/renova a sessão e a indexa no conjunto do usuário.
func (s *Store) SessionTouch(sessionKey, identity string, fields map[string]any, ttl int) error {
	if sessionKey == "" || ttl <= 0 {
		return nil
	}
	if fields == nil {
		fields = map[string]any{}
	}
	fields["identity"] = identity
	fields["updated_epoch"] = time.Now().Unix()
	payload, err := json.Marshal(fields)
	if err != nil {
		return err
	}
	cmds := [][]string{{"SETEX", NS + "sess:" + sessionKey, strconv.Itoa(ttl), string(payload)}}
	if identity != "" {
		cmds = append(cmds,
			[]string{"SADD", NS + "user:" + identity, sessionKey},
			[]string{"EXPIRE", NS + "user:" + identity, strconv.Itoa(userSetTTL)},
		)
	}
	_, err = s.do(cmds...)
	return err
}

func (s *Store) SessionClose(sessionKey, identity string) error {
	if sessionKey == "" {
		return nil
	}
	cmds := [][]string{{"DEL", NS + "sess:" + sessionKey}}
	if identity != "" {
		cmds = append(cmds, []string{"SREM", NS + "user:" + identity, sessionKey})
	}
	_, err := s.do(cmds...)
	return err
}

// UserCount conta sessões vivas do usuário e PODA o índice na leitura — é o que
// permite o limite de conexão sem varredura, igual StateStore::userSessions().
func (s *Store) UserCount(identity string) (int, error) {
	if identity == "" {
		return 0, nil
	}
	res, err := s.do([]string{"SMEMBERS", NS + "user:" + identity})
	if err != nil {
		return 0, err
	}
	members, _ := res[0].([]any)
	alive := 0
	var stale []string
	for _, m := range members {
		key, _ := m.(string)
		if key == "" {
			continue
		}
		got, err := s.do([]string{"EXISTS", NS + "sess:" + key})
		if err != nil {
			return alive, err
		}
		if n, _ := got[0].(int64); n == 1 {
			alive++
		} else {
			stale = append(stale, key)
		}
	}
	for _, key := range stale {
		_, _ = s.do([]string{"SREM", NS + "user:" + identity, key})
	}
	return alive, nil
}

// PresenceSet publica o heartbeat do nó em cdnv:lb:<id>.
func (s *Store) PresenceSet(lbID int, payload map[string]any, ttl int) error {
	if lbID <= 0 {
		return nil
	}
	payload["epoch"] = time.Now().Unix()
	raw, err := json.Marshal(payload)
	if err != nil {
		return err
	}
	_, err = s.do([]string{"SETEX", NS + "lb:" + strconv.Itoa(lbID), strconv.Itoa(ttl), string(raw)})
	return err
}

func (s *Store) Close() {
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.conn != nil {
		_ = s.conn.Close()
		s.conn = nil
	}
}