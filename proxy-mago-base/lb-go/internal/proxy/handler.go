package proxy

import (
	"crypto/sha1"
	"encoding/hex"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"

	"github.com/aryperdomo123456789-web/cdnvoods/lb-go/internal/contract"
	"github.com/aryperdomo123456789-web/cdnvoods/lb-go/internal/events"
	"github.com/aryperdomo123456789-web/cdnvoods/lb-go/internal/state"
)

// Handler é o motor quente. Ele NÃO tem regra própria: lê o snapshot do
// contrato, aplica, entrega o byte e conta o que fez em eventos.
type Handler struct {
	Contract     *contract.Client
	State        *state.Store
	Events       *events.Queue
	PublicScheme string
	MaxHops      int
	Upstream     *http.Client
	Logf         func(string, ...any)
	trustedBrain map[string]struct{}
	brainBaseURL string
	lbToken      string
	brainFetch   map[string]struct{}
}

func New(c *contract.Client, st *state.Store, q *events.Queue, scheme string, maxHops int, timeout time.Duration, brainBaseURL, lbToken, brainDirectFetchHosts string) *Handler {
	headerTimeout := timeout
	if headerTimeout < 45*time.Second {
		headerTimeout = 45 * time.Second
	}
	return &Handler{
		Contract:     c,
		State:        st,
		Events:       q,
		PublicScheme: scheme,
		MaxHops:      maxHops,
		Upstream: &http.Client{
			// Redirect é seguido À MÃO para registrar hops de direct source.
			CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
			Timeout:       0, // stream longo não pode morrer por timeout global
			Transport: &http.Transport{
				Proxy:                 http.ProxyFromEnvironment,
				DialContext:           (&net.Dialer{Timeout: timeout, KeepAlive: 30 * time.Second}).DialContext,
				ResponseHeaderTimeout: headerTimeout,
				MaxIdleConns:          2048,
				MaxIdleConnsPerHost:   512,
				MaxConnsPerHost:       0,
				IdleConnTimeout:       120 * time.Second,
				TLSHandshakeTimeout:   10 * time.Second,
				ExpectContinueTimeout: 1 * time.Second,
				ForceAttemptHTTP2:     false,
			},
		},
		Logf:         func(string, ...any) {},
		trustedBrain: trustedBrainAddrs(brainBaseURL),
		brainBaseURL: strings.TrimRight(brainBaseURL, "/"),
		lbToken:      strings.TrimSpace(lbToken),
		brainFetch:   hostSet(brainDirectFetchHosts),
	}
}

func hostSet(raw string) map[string]struct{} {
	out := map[string]struct{}{}
	for _, item := range strings.FieldsFunc(strings.ToLower(strings.TrimSpace(raw)), func(r rune) bool {
		return r == ' ' || r == ',' || r == ';' || r == '\n' || r == '\t' || r == '\r'
	}) {
		item = strings.TrimSpace(item)
		if item != "" {
			out[item] = struct{}{}
		}
	}
	return out
}

func hostIs(host, want string) bool {
	host = strings.ToLower(strings.TrimSpace(host))
	want = strings.ToLower(strings.TrimSpace(want))
	return host == want || strings.HasSuffix(host, "."+want)
}

func signedHighCdnHost(host string) bool {
	return hostIs(host, "highcdnvideo.link")
}

func clientIP(r *http.Request) string {
	for _, h := range []string{"CF-Connecting-IP", "X-Real-IP"} {
		if v := strings.TrimSpace(r.Header.Get(h)); v != "" {
			return v
		}
	}
	if v := r.Header.Get("X-Forwarded-For"); v != "" {
		return strings.TrimSpace(strings.Split(v, ",")[0])
	}
	host, _, err := net.SplitHostPort(r.RemoteAddr)
	if err != nil {
		return r.RemoteAddr
	}
	return host
}

func trustedBrainAddrs(rawURL string) map[string]struct{} {
	out := map[string]struct{}{}
	u, err := url.Parse(strings.TrimSpace(rawURL))
	if err != nil || u.Hostname() == "" {
		return out
	}
	out[strings.ToLower(u.Hostname())] = struct{}{}
	ips, err := net.LookupIP(u.Hostname())
	if err != nil {
		return out
	}
	for _, ip := range ips {
		out[ip.String()] = struct{}{}
	}
	return out
}

func remoteIP(r *http.Request) string {
	host, _, err := net.SplitHostPort(r.RemoteAddr)
	if err != nil {
		return strings.TrimSpace(r.RemoteAddr)
	}
	return strings.TrimSpace(host)
}

func (h *Handler) trustedBrainRequest(r *http.Request) bool {
	if strings.TrimSpace(r.Header.Get("X-Cdn-Brain-Proxy")) != "1" {
		return false
	}
	ip := remoteIP(r)
	if ip == "" {
		return false
	}
	_, ok := h.trustedBrain[ip]
	return ok
}

func (h *Handler) publicHost(r *http.Request) string {
	if h.trustedBrainRequest(r) {
		if v := strings.TrimSpace(r.Header.Get("X-Cdn-Original-Host")); v != "" {
			return v
		}
		if v := strings.TrimSpace(r.Header.Get("X-Forwarded-Host")); v != "" {
			return v
		}
	}
	return r.Host
}

func (h *Handler) publicClientIP(r *http.Request) string {
	if h.trustedBrainRequest(r) {
		if v := strings.TrimSpace(r.Header.Get("X-Cdn-Original-IP")); v != "" {
			return v
		}
	}
	return clientIP(r)
}

// credentials aceita os dois formatos do XUI: query (get.php/player_api) e
// path (/live/user/pass/123.ts, /movie/..., /series/...).
func credentials(r *http.Request) (string, string) {
	q := r.URL.Query()
	user, pass := q.Get("username"), q.Get("password")
	if user != "" {
		return user, pass
	}
	parts := strings.Split(strings.Trim(r.URL.Path, "/"), "/")
	if len(parts) >= 3 {
		switch strings.ToLower(parts[0]) {
		case "live", "movie", "series", "timeshift":
			return parts[1], parts[2]
		}
		// XUI também serve /user/pass/id.ts sem prefixo de tipo.
		if len(parts) == 3 && strings.Contains(parts[2], ".") {
			return parts[0], parts[1]
		}
	}
	return user, pass
}

// isPlaylist decide reescrita textual (linha a linha) x passthrough binário.
func isPlaylist(path string) bool {
	p := strings.ToLower(path)
	for _, suf := range []string{".m3u", ".m3u8", ".php", ".xml", ".json"} {
		if strings.HasSuffix(p, suf) {
			return true
		}
	}
	return false
}

func isLive(path string) bool {
	p := strings.ToLower(path)
	return strings.HasPrefix(p, "/live") || strings.HasSuffix(p, ".ts") || strings.HasSuffix(p, ".m3u8")
}

func sessionKey(username, ip, ua string) string {
	sum := sha1.Sum([]byte(strings.ToLower(username) + "|" + ip + "|" + ua))
	return hex.EncodeToString(sum[:])[:32]
}

func (h *Handler) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	if r.URL.Path == "/lb-direct-fetch.php" {
		h.serveDirectFetch(w, r)
		return
	}
	start := time.Now()
	snap := h.Contract.Current()
	if snap == nil {
		http.Error(w, "muscle sem snapshot do cerebro", http.StatusServiceUnavailable)
		return
	}

	host := h.publicHost(r)
	ip := h.publicClientIP(r)
	ua := r.Header.Get("User-Agent")
	username, password := credentials(r)
	path := r.URL.Path
	query := r.URL.RawQuery
	reqID := sessionKey(path+query, ip, strconv.FormatInt(start.UnixNano(), 36))[:16]

	base := events.Event{
		"host": host, "client_ip": ip, "path": path, "query": query,
		"username": username, "password": password, "user_agent": ua,
		"request_id": reqID,
	}

	reject := func(status int, reason string) {
		e := events.Event{"type": "session_reject", "reason": reason, "status": status}
		for k, v := range base {
			e[k] = v
		}
		h.Events.Push(e)
		http.Error(w, reason, status)
	}

	rt := snap.Runtime

	if rt.AllowedUserAgent != "" && !strings.Contains(ua, rt.AllowedUserAgent) {
		reject(http.StatusForbidden, "user_agent_bloqueado")
		return
	}

	origin := snap.ResolveOrigin(host)
	if origin == nil {
		reject(http.StatusServiceUnavailable, "origem_indisponivel")
		return
	}

	key := sessionKey(username, ip, ua)
	user, known := snap.User(username)

	if known {
		if user.Expired(time.Now()) {
			reject(http.StatusForbidden, "assinatura_expirada")
			return
		}
		if rt.EnforceIPLock && !user.IPAllowed(ip) {
			reject(http.StatusForbidden, "ip_nao_autorizado")
			return
		}
		if rt.EnforceConnectionLimit && user.MaxConnections > 0 && isLive(path) {
			// Estado vivo fora do ar NÃO derruba player: sem contador confiável
			// o limite é liberado e a degradação aparece em /healthz.
			n, err := h.State.UserCount(strings.ToLower(username))
			if err == nil && n >= user.MaxConnections {
				// A própria sessão deste cliente já conta: só recusa quando o
				// excedente vem de OUTRA sessão viva.
				self, reliable := h.State.SessionExists(key)
				if reliable && (!self || n > user.MaxConnections) {
					reject(http.StatusTooManyRequests, "limite_de_conexoes")
					return
				}
			}
		}
	}

	ttl := rt.SessionTTLVod
	if isLive(path) {
		ttl = rt.SessionTTLLive
	}
	if ttl <= 0 {
		ttl = 120
	}

	if rt.SessionsEnabled {
		_ = h.State.SessionTouch(key, strings.ToLower(username), map[string]any{
			"kind": map[bool]string{true: "live", false: "vod"}[isLive(path)],
			"ip":   ip, "host": host, "lb_id": snap.LB.ID, "path": path,
		}, ttl)
		e := events.Event{"type": "session_open", "session_key": key}
		for k, v := range base {
			e[k] = v
		}
		h.Events.Push(e)
	}

	status, bytesOut, directHost, hops, err := h.deliver(w, r, snap, origin, username, password)
	if err != nil {
		h.Logf("[hot] %s %s -> %v", ip, path, err)
	}

	e := events.Event{
		"type": "request", "session_key": key, "status": status, "bytes": bytesOut,
		"direct_host": directHost, "hops": hops, "origin_id": origin.ID,
		"duration_ms": time.Since(start).Milliseconds(),
	}
	for k, v := range base {
		e[k] = v
	}
	h.Events.Push(e)

	if rt.SessionsEnabled && !isLive(path) {
		_ = h.State.SessionClose(key, strings.ToLower(username))
		c := events.Event{"type": "session_close", "session_key": key, "direct_host": directHost}
		for k, v := range base {
			c[k] = v
		}
		h.Events.Push(c)
	}
}

func (h *Handler) serveDirectFetch(w http.ResponseWriter, r *http.Request) {
	target := strings.TrimSpace(r.URL.Query().Get("target"))
	if target == "" {
		http.Error(w, "missing_target", http.StatusBadRequest)
		return
	}
	parts, err := url.Parse(target)
	if err != nil || parts.Hostname() == "" {
		http.Error(w, "invalid_target", http.StatusBadRequest)
		return
	}
	current := target
	hops := 0
	for {
		currentHost := ""
		if u, err := url.Parse(current); err == nil {
			currentHost = strings.ToLower(strings.TrimSpace(u.Hostname()))
		}
		req, err := http.NewRequestWithContext(r.Context(), http.MethodGet, current, nil)
		if err != nil {
			http.Error(w, "upstream invalido", http.StatusBadGateway)
			return
		}
		for _, k := range []string{"Range", "Accept", "Accept-Encoding", "User-Agent"} {
			if v := r.Header.Get(k); v != "" {
				req.Header.Set(k, v)
			}
		}
		if token := strings.TrimSpace(r.Header.Get("X-Relay-Token")); token != "" {
			req.Header.Set("X-Relay-Token", token)
		}
		resp, err := h.Upstream.Do(req)
		if err != nil {
			http.Error(w, "relay_fetch_failed", http.StatusBadGateway)
			return
		}

		if loc := resp.Header.Get("Location"); loc != "" && (resp.StatusCode >= 300 && resp.StatusCode < 400 || resp.StatusCode == http.StatusTooManyRequests) {
			resp.Body.Close()
			if hops >= h.MaxHops {
				http.Error(w, "limite de redirecionamentos", http.StatusBadGateway)
				return
			}
			next, err := url.Parse(loc)
			if err != nil {
				http.Error(w, "redirect invalido", http.StatusBadGateway)
				return
			}
			if !next.IsAbs() {
				cur, _ := url.Parse(current)
				next = cur.ResolveReference(next)
			}
			nextHost := strings.ToLower(strings.TrimSpace(next.Hostname()))
			if resp.StatusCode == http.StatusTooManyRequests && !hostIs(currentHost, "readyondemand.click") {
				http.Error(w, "too_many_requests", http.StatusTooManyRequests)
				return
			}
			if resp.StatusCode == http.StatusTooManyRequests {
				if signedHighCdnHost(nextHost) {
					if h.Logf != nil {
						h.Logf("[hot] hop=%d status=429 mode=external_direct path=%s next_host=%s",
							hops+1, r.URL.Path, nextHost)
					}
				} else {
					http.Error(w, "too_many_requests", http.StatusTooManyRequests)
					return
				}
			}
			current = next.String()
			hops++
			continue
		}

		defer resp.Body.Close()
		for _, k := range []string{"Content-Type", "Content-Length", "Accept-Ranges", "Content-Range", "Cache-Control", "Expires"} {
			if v := resp.Header.Get(k); v != "" {
				w.Header().Set(k, v)
			}
		}
		w.WriteHeader(resp.StatusCode)
		_, _ = io.CopyBuffer(w, resp.Body, make([]byte, 64*1024))
		return
	}
}

// deliver busca na origem e entrega ao cliente sem vazar a origem.
func (h *Handler) deliver(
	w http.ResponseWriter, r *http.Request, snap *contract.Snapshot,
	o *contract.Origin, username, password string,
) (int, int64, string, int, error) {
	target := h.originURL(o, r, username, password)
	rw := NewRewriter(o, hostOnly(r.Host), h.PublicScheme, "")

	hops := 0
	directHost := ""
	current := target
	targetHost := ""
	if u, err := url.Parse(target); err == nil {
		targetHost = strings.ToLower(strings.TrimSpace(u.Hostname()))
	}
	originHost := strings.ToLower(strings.TrimSpace(o.Host))
	mode := "external_direct"
	if targetHost != "" && targetHost == originHost {
		mode = "origin_xui"
	}

	for {
		currentHost := ""
		if u, err := url.Parse(current); err == nil {
			currentHost = strings.ToLower(strings.TrimSpace(u.Hostname()))
		}
		req, err := http.NewRequestWithContext(r.Context(), r.Method, current, nil)
		if err != nil {
			http.Error(w, "upstream invalido", http.StatusBadGateway)
			return http.StatusBadGateway, 0, directHost, hops, err
		}
		copyClientHeaders(r, req)
		if hops == 0 && o.HostHeader != "" {
			req.Host = o.HostHeader
		}

		resp, err := h.Upstream.Do(req)
		if err != nil {
			http.Error(w, "origem indisponivel", http.StatusBadGateway)
			return http.StatusBadGateway, 0, directHost, hops, err
		}

		if loc := resp.Header.Get("Location"); loc != "" && (resp.StatusCode >= 300 && resp.StatusCode < 400 || resp.StatusCode == http.StatusTooManyRequests) {
			resp.Body.Close()
			if hops >= h.MaxHops {
				http.Error(w, "limite de redirecionamentos", http.StatusBadGateway)
				return http.StatusBadGateway, 0, directHost, hops, nil
			}
			next, err := url.Parse(loc)
			if err != nil {
				http.Error(w, "redirect invalido", http.StatusBadGateway)
				return http.StatusBadGateway, 0, directHost, hops, err
			}
			if !next.IsAbs() {
				cur, _ := url.Parse(current)
				next = cur.ResolveReference(next)
			}
			hops++
			nextHost := strings.ToLower(strings.TrimSpace(next.Hostname()))
			if resp.StatusCode == http.StatusTooManyRequests {
				if !hostIs(currentHost, "readyondemand.click") {
					if h.Logf != nil {
						h.Logf("[hot] hop=%d status=429 mode=%s path=%s host=%s",
							hops, mode, r.URL.Path, currentHost)
					}
					http.Error(w, "too_many_requests", http.StatusTooManyRequests)
					return http.StatusTooManyRequests, 0, directHost, hops, nil
				}
				if !signedHighCdnHost(nextHost) {
					if h.Logf != nil {
						h.Logf("[hot] hop=%d status=429 mode=external_direct path=%s host=%s next_host=%s",
							hops, r.URL.Path, currentHost, nextHost)
					}
					http.Error(w, "too_many_requests", http.StatusTooManyRequests)
					return http.StatusTooManyRequests, 0, directHost, hops, nil
				}
				if h.Logf != nil {
					h.Logf("[hot] hop=%d status=429 mode=external_direct path=%s next_host=%s",
						hops, r.URL.Path, nextHost)
				}
			}
			if nextHost != "" && !strings.EqualFold(nextHost, originHost) {
				directHost = nextHost
				rw.AddHost(directHost)
				mode = "external_direct"
			}
			if resp.StatusCode >= 300 && resp.StatusCode < 400 && h.Logf != nil {
				h.Logf("[hot] hop=%d status=%d mode=%s path=%s next_host=%s",
					hops, resp.StatusCode, mode, r.URL.Path, nextHost)
			}
			current = next.String()
			continue
		}

		if resp.StatusCode == http.StatusTooManyRequests && h.Logf != nil {
			h.Logf("[hot] hop=%d status=429 mode=%s path=%s host=%s",
				hops, mode, r.URL.Path, currentHost)
		}

		defer resp.Body.Close()
		copyUpstreamHeaders(resp, w)

		if isPlaylist(r.URL.Path) {
			forcedPlaylistHeaders(r, w)
			n, err := streamRewritten(w, resp, rw)
			return resp.StatusCode, n, directHost, hops, err
		}
		w.WriteHeader(resp.StatusCode)
		n, err := io.CopyBuffer(w, resp.Body, make([]byte, 64*1024))
		return resp.StatusCode, n, directHost, hops, err
	}
}

func (h *Handler) shouldBrainFetch(host string) bool {
	host = strings.ToLower(strings.TrimSpace(host))
	if host == "" || len(h.brainFetch) == 0 {
		return false
	}
	_, ok := h.brainFetch[host]
	return ok
}

func (h *Handler) brainFetchURL(target string) string {
	return h.brainBaseURL + "/lb-direct-fetch.php?target=" + url.QueryEscape(target)
}

func isMediaPath(path string) bool {
	path = strings.ToLower(path)
	return strings.HasPrefix(path, "/movie/") || strings.HasPrefix(path, "/series/")
}

func (h *Handler) originURL(o *contract.Origin, r *http.Request, username, password string) string {
	scheme := o.Scheme
	if scheme != "https" {
		scheme = "http"
	}
	hostport := o.Host
	if o.Port > 0 && o.Port != 80 && o.Port != 443 {
		hostport = net.JoinHostPort(o.Host, strconv.Itoa(o.Port))
	}
	path := r.URL.Path
	if o.BasePath != "" {
		path = "/" + strings.Trim(o.BasePath, "/") + path
	}

	q := r.URL.Query()
	q.Del("t") // token público do cérebro nunca vai para a origem
	// Credencial da origem só entra quando o assinante não mandou nada
	// (origem com conta única) — mesma regra do StreamProxy PHP.
	if username == "" && o.AuthUser != "" {
		q.Set("username", o.AuthUser)
		q.Set("password", o.AuthPass)
	}
	out := scheme + "://" + hostport + path
	if enc := q.Encode(); enc != "" {
		out += "?" + enc
	}
	return out
}

func hostOnly(h string) string {
	if i := strings.IndexByte(h, ':'); i >= 0 {
		return strings.ToLower(h[:i])
	}
	return strings.ToLower(h)
}

func copyClientHeaders(r *http.Request, req *http.Request) {
	for _, k := range []string{"Range", "Accept", "Accept-Encoding", "User-Agent", "Icy-MetaData"} {
		if v := r.Header.Get(k); v != "" {
			req.Header.Set(k, v)
		}
	}
}

// copyUpstreamHeaders só deixa passar cabeçalho inofensivo. Nada que revele a
// origem (Server, Location, X-Powered-By, Set-Cookie) sai daqui.
func copyUpstreamHeaders(resp *http.Response, w http.ResponseWriter) {
	for _, k := range []string{"Content-Type", "Content-Length", "Accept-Ranges", "Content-Range", "Cache-Control", "Expires"} {
		if v := resp.Header.Get(k); v != "" {
			w.Header().Set(k, v)
		}
	}
	if isPlaylistCT(resp.Header.Get("Content-Type")) {
		// tamanho muda depois da reescrita
		w.Header().Del("Content-Length")
	}
}

func forcedPlaylistHeaders(r *http.Request, w http.ResponseWriter) {
	path := strings.ToLower(r.URL.Path)
	switch {
	case strings.HasSuffix(path, "get.php"):
		output := strings.ToLower(strings.TrimSpace(r.URL.Query().Get("output")))
		if output == "hls" || output == "m3u8" {
			w.Header().Set("Content-Type", "application/vnd.apple.mpegurl")
		} else {
			w.Header().Set("Content-Type", "audio/x-mpegurl")
		}
		w.Header().Set("Content-Disposition", `attachment; filename="playlist.m3u"`)
	case strings.HasSuffix(path, ".m3u8"):
		w.Header().Set("Content-Type", "application/vnd.apple.mpegurl")
	case strings.HasSuffix(path, ".m3u"):
		w.Header().Set("Content-Type", "audio/x-mpegurl")
	}
}

func isPlaylistCT(ct string) bool {
	ct = strings.ToLower(ct)
	return strings.Contains(ct, "mpegurl") || strings.Contains(ct, "text/") ||
		strings.Contains(ct, "json") || strings.Contains(ct, "xml")
}

// streamRewritten reescreve linha a linha com memória constante: playlist de
// 90 MB passa sem estourar RAM.
func streamRewritten(w http.ResponseWriter, resp *http.Response, rw *Rewriter) (int64, error) {
	w.Header().Del("Content-Length")
	w.WriteHeader(resp.StatusCode)
	flusher, _ := w.(http.Flusher)

	var written int64
	buf := make([]byte, 0, 8*1024)
	chunk := make([]byte, 32*1024)
	writeLine := func(line []byte, eol string) error {
		out := rw.Line(string(line)) + eol
		n, err := w.Write([]byte(out))
		written += int64(n)
		return err
	}

	for {
		n, err := resp.Body.Read(chunk)
		if n > 0 {
			buf = append(buf, chunk[:n]...)
			for {
				i := indexByte(buf, '\n')
				if i < 0 {
					break
				}
				line := buf[:i]
				eol := "\n"
				if len(line) > 0 && line[len(line)-1] == '\r' {
					line = line[:len(line)-1]
					eol = "\r\n"
				}
				if werr := writeLine(line, eol); werr != nil {
					return written, werr
				}
				buf = buf[i+1:]
			}
			if flusher != nil {
				flusher.Flush()
			}
		}
		if err == io.EOF {
			if len(buf) > 0 {
				if werr := writeLine(buf, ""); werr != nil {
					return written, werr
				}
			}
			return written, nil
		}
		if err != nil {
			return written, err
		}
	}
}

func indexByte(b []byte, c byte) int {
	for i := range b {
		if b[i] == c {
			return i
		}
	}
	return -1
}

// Healthz é o diagnóstico do músculo (usado pelo cérebro e pelo systemd).
func (h *Handler) Healthz(w http.ResponseWriter, _ *http.Request) {
	snap := h.Contract.Current()
	age := "sem snapshot"
	lb := 0
	if snap != nil {
		age = fmt.Sprintf("%.0fs", snap.Age().Seconds())
		lb = snap.LB.ID
	}
	w.Header().Set("Content-Type", "application/json")
	fmt.Fprintf(w,
		`{"ok":true,"engine":"go","contract_version":%q,"lb_id":%d,"snapshot_age":%q,"snapshot_error":%q,"state_degraded":%v,"events":%s}`,
		contract.Version, lb, age, h.Contract.LastError(), h.State.Degraded(), jsonMap(h.Events.Stats()),
	)
}

func jsonMap(m map[string]any) string {
	parts := make([]string, 0, len(m))
	for k, v := range m {
		parts = append(parts, fmt.Sprintf("%q:%v", k, v))
	}
	return "{" + strings.Join(parts, ",") + "}"
}
