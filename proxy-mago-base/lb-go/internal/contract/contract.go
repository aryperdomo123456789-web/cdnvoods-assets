// Package contract fala o CONTRATO v1 do cérebro (docs/CONTRATO_LB_V1.md).
//
// Nada de regra nova: o snapshot manda, o motor obedece. Campo desconhecido é
// ignorado (compat futura). Major diferente => modo conservador: continua
// servindo com o último snapshot válido e NÃO aplica regra nova.
package contract

import (
	"encoding/json"
	"fmt"
	"net"
	"net/http"
	"strings"
	"sync"
	"time"
)

const Version = "1.0"
const Major = 1

type LB struct {
	ID       int    `json:"id"`
	Label    string `json:"label"`
	PublicIP string `json:"public_ip"`
	Enabled  bool   `json:"enabled"`
	Drain    bool   `json:"drain"`
}

type Runtime struct {
	SessionsEnabled        bool   `json:"sessions_enabled"`
	EnforceIPLock          bool   `json:"enforce_ip_lock"`
	EnforceConnectionLimit bool   `json:"enforce_connection_limit"`
	FollowDirectSource     bool   `json:"follow_direct_source"`
	RequireToken           bool   `json:"require_token"`
	AllowedUserAgent       string `json:"allowed_user_agent"`
	RateLimitPerMinute     int    `json:"rate_limit_per_minute"`
	SessionTTLLive         int    `json:"session_ttl_live"`
	SessionTTLVod          int    `json:"session_ttl_vod"`
	LogRequests            bool   `json:"log_requests"`
	LbRequireDelivery      bool   `json:"lb_require_delivery"`
	LbDefaultMode          string `json:"lb_default_mode"`
}

type Origin struct {
	ID         int    `json:"id"`
	Label      string `json:"label"`
	Scheme     string `json:"scheme"`
	Host       string `json:"host"`
	Port       int    `json:"port"`
	HostHeader string `json:"host_header"`
	ExtraHosts string `json:"extra_hosts"`
	BasePath   string `json:"base_path"`
	AuthUser   string `json:"auth_user"`
	AuthPass   string `json:"auth_pass"`
	Active     bool   `json:"active"`
}

type Alias struct {
	ID       int    `json:"id"`
	Hostname string `json:"hostname"`
	OriginID int    `json:"origin_id"`
	Active   bool   `json:"active"`
}

type User struct {
	Username       string   `json:"username"`
	MaxConnections int      `json:"max_connections"`
	ExpDate        string   `json:"exp_date"`
	AllowedIPs     []string `json:"allowed_ips"`
	IPLocked       bool     `json:"ip_locked"`

	nets []*net.IPNet
	ips  []net.IP
}

// IPAllowed aplica a trava por IP com suporte a CIDR, igual app/UserIpLock.php.
func (u *User) IPAllowed(raw string) bool {
	if !u.IPLocked {
		return true
	}
	ip := net.ParseIP(strings.TrimSpace(raw))
	if ip == nil {
		return false
	}
	for _, n := range u.nets {
		if n.Contains(ip) {
			return true
		}
	}
	for _, allowed := range u.ips {
		if allowed.Equal(ip) {
			return true
		}
	}
	return false
}

// Expired usa o exp_date do XUI (epoch ou datetime). Vazio = sem validade.
func (u *User) Expired(now time.Time) bool {
	raw := strings.TrimSpace(u.ExpDate)
	if raw == "" || raw == "0" || strings.EqualFold(raw, "null") {
		return false
	}
	if ts, err := time.Parse("2006-01-02 15:04:05", raw); err == nil {
		return ts.Before(now)
	}
	var epoch int64
	if _, err := fmt.Sscanf(raw, "%d", &epoch); err == nil && epoch > 0 {
		return time.Unix(epoch, 0).Before(now)
	}
	return false
}

type StateBlock struct {
	Driver          string `json:"driver"`
	EffectiveDriver string `json:"effective_driver"`
	Degraded        bool   `json:"degraded"`
	Namespace       string `json:"namespace"`
}

type Brain struct {
	EventsURL      string `json:"events_url"`
	SnapshotURL    string `json:"snapshot_url"`
	HeartbeatURL   string `json:"heartbeat_url"`
	EventsMaxBatch int    `json:"events_max_batch"`
	AuthHeader     string `json:"auth_header"`
}

type Snapshot struct {
	OK              bool       `json:"ok"`
	Contract        string     `json:"contract"`
	ContractVersion string     `json:"contract_version"`
	GeneratedEpoch  int64      `json:"generated_epoch"`
	TTL             int        `json:"ttl"`
	LB              LB         `json:"lb"`
	State           StateBlock `json:"state"`
	Runtime         Runtime    `json:"runtime"`
	Origins         []Origin   `json:"origins"`
	Aliases         []Alias    `json:"aliases"`
	Users           []User     `json:"users"`
	Brain           Brain      `json:"brain"`

	originByID   map[int]*Origin
	aliasByHost  map[string]*Alias
	userByName   map[string]*User
	fetchedAt    time.Time
	Conservative bool
}

func (s *Snapshot) index() {
	s.originByID = make(map[int]*Origin, len(s.Origins))
	for i := range s.Origins {
		s.originByID[s.Origins[i].ID] = &s.Origins[i]
	}
	s.aliasByHost = make(map[string]*Alias, len(s.Aliases))
	for i := range s.Aliases {
		host := strings.ToLower(strings.TrimSpace(s.Aliases[i].Hostname))
		s.aliasByHost[host] = &s.Aliases[i]
	}
	s.userByName = make(map[string]*User, len(s.Users))
	for i := range s.Users {
		u := &s.Users[i]
		for _, raw := range u.AllowedIPs {
			raw = strings.TrimSpace(raw)
			if raw == "" {
				continue
			}
			if strings.Contains(raw, "/") {
				if _, n, err := net.ParseCIDR(raw); err == nil {
					u.nets = append(u.nets, n)
				}
				continue
			}
			if ip := net.ParseIP(raw); ip != nil {
				u.ips = append(u.ips, ip)
			}
		}
		s.userByName[strings.ToLower(u.Username)] = u
	}
}

// ResolveOrigin encontra a origem para um host público (alias).
// Sem alias específico, cai na primeira origem ativa — mesmo comportamento do
// caminho quente PHP quando o vhost não tem alias dedicado.
func (s *Snapshot) ResolveOrigin(host string) *Origin {
	host = strings.ToLower(host)
	if i := strings.IndexByte(host, ':'); i >= 0 {
		host = host[:i]
	}
	if a, ok := s.aliasByHost[host]; ok && a.Active {
		if o, ok := s.originByID[a.OriginID]; ok && o.Active {
			return o
		}
	}
	for i := range s.Origins {
		if s.Origins[i].Active {
			return &s.Origins[i]
		}
	}
	return nil
}

func (s *Snapshot) User(username string) (*User, bool) {
	u, ok := s.userByName[strings.ToLower(strings.TrimSpace(username))]
	return u, ok
}

func (s *Snapshot) Age() time.Duration { return time.Since(s.fetchedAt) }

// Client mantém o último snapshot válido em memória e o renova em background.
type Client struct {
	baseURL string
	token   string
	http    *http.Client

	mu   sync.RWMutex
	snap *Snapshot
	err  string
}

func NewClient(baseURL, token string) *Client {
	return &Client{
		baseURL: strings.TrimRight(baseURL, "/"),
		token:   token,
		http:    &http.Client{Timeout: 10 * time.Second},
	}
}

// Current devolve o snapshot em uso (pode estar velho: melhor velho que nada,
// player não cai porque o cérebro piscou).
func (c *Client) Current() *Snapshot {
	c.mu.RLock()
	defer c.mu.RUnlock()
	return c.snap
}

func (c *Client) LastError() string {
	c.mu.RLock()
	defer c.mu.RUnlock()
	return c.err
}

func (c *Client) Refresh() error {
	url := c.baseURL + "/lb-contract.php?contract_version=" + Version
	req, err := http.NewRequest(http.MethodGet, url, nil)
	if err != nil {
		return err
	}
	req.Header.Set("X-LB-Token", c.token)
	req.Header.Set("Accept", "application/json")

	resp, err := c.http.Do(req)
	if err != nil {
		c.setErr(err.Error())
		return err
	}
	defer resp.Body.Close()

	switch resp.StatusCode {
	case http.StatusOK:
	case http.StatusConflict:
		// Major divergente: modo conservador, mantém o snapshot anterior.
		c.mu.Lock()
		if c.snap != nil {
			c.snap.Conservative = true
		}
		c.err = "contrato incompatível (409): modo conservador"
		c.mu.Unlock()
		return fmt.Errorf("contrato incompatível")
	default:
		e := fmt.Sprintf("snapshot http %d", resp.StatusCode)
		c.setErr(e)
		return fmt.Errorf("%s", e)
	}

	var snap Snapshot
	if err := json.NewDecoder(resp.Body).Decode(&snap); err != nil {
		c.setErr("json inválido: " + err.Error())
		return err
	}
	if !versionCompatible(snap.ContractVersion) {
		c.setErr("major divergente: " + snap.ContractVersion)
		return fmt.Errorf("major divergente")
	}
	snap.fetchedAt = time.Now()
	snap.index()

	c.mu.Lock()
	c.snap = &snap
	c.err = ""
	c.mu.Unlock()
	return nil
}

func (c *Client) setErr(msg string) {
	c.mu.Lock()
	c.err = msg
	c.mu.Unlock()
}

func versionCompatible(reported string) bool {
	if reported == "" {
		return true // campo ausente não é fatal
	}
	var major int
	if _, err := fmt.Sscanf(reported, "%d", &major); err != nil {
		return false
	}
	return major == Major
}

// Loop renova o snapshot para sempre. Falha nunca derruba o motor.
func (c *Client) Loop(every time.Duration, logf func(string, ...any)) {
	if err := c.Refresh(); err != nil {
		logf("[contract] primeiro snapshot falhou: %v", err)
	}
	t := time.NewTicker(every)
	defer t.Stop()
	for range t.C {
		if err := c.Refresh(); err != nil {
			logf("[contract] refresh falhou (segue com snapshot antigo): %v", err)
		}
	}
}