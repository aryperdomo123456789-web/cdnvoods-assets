// Package proxy é o caminho quente do músculo.
//
// rewrite.go replica app/PlaylistRewriter.php: nenhum host/porta/credencial da
// origem pode sair no corpo. A reescrita é COMPILADA uma vez por request e
// aplicada LINHA A LINHA, para playlist de 90 MB não virar RAM.
package proxy

import (
	"net/url"
	"strings"

	"github.com/aryperdomo123456789-web/cdnvoods/lb-go/internal/contract"
)

type Rewriter struct {
	pairs      []replacement // host -> base pública
	creds      []string
	token      string
	publicBase string
	publicHost string
}

type replacement struct{ from, to string }

// NewRewriter compila os padrões da origem para o host público do request.
func NewRewriter(o *contract.Origin, publicHost, scheme, token string) *Rewriter {
	if scheme != "https" {
		scheme = "http"
	}
	r := &Rewriter{
		token:      token,
		publicHost: publicHost,
		publicBase: scheme + "://" + publicHost,
	}
	for _, h := range originHosts(o) {
		r.addHostPatterns(h)
	}
	for _, c := range []string{o.AuthUser, o.AuthPass} {
		if len(c) >= 3 {
			r.creds = append(r.creds, c)
			if enc := url.QueryEscape(c); enc != c {
				r.creds = append(r.creds, enc)
			}
		}
	}
	return r
}

func originHosts(o *contract.Origin) []string {
	seen := map[string]bool{}
	var out []string
	add := func(h string) {
		h = strings.ToLower(strings.TrimSpace(h))
		if h == "" || seen[h] {
			return
		}
		seen[h] = true
		out = append(out, h)
	}
	add(o.Host)
	add(o.HostHeader)
	for _, h := range strings.FieldsFunc(o.ExtraHosts, func(r rune) bool {
		return r == ' ' || r == ',' || r == ';' || r == '\n' || r == '\t' || r == '\r'
	}) {
		add(h)
	}
	return out
}

// AddHost registra host descoberto em runtime (direct source): a partir do
// momento em que seguimos um 302 para um CDN de terceiros, aquele host é tão
// sensível quanto a origem.
func (r *Rewriter) AddHost(host string) {
	host = strings.ToLower(strings.TrimSpace(host))
	if host == "" || host == r.publicHost {
		return
	}
	for _, p := range r.pairs {
		if p.from == "//"+host {
			return
		}
	}
	r.addHostPatterns(host)
}

func (r *Rewriter) addHostPatterns(h string) {
	escaped := strings.ReplaceAll(r.publicBase, "/", "\\/")
	r.pairs = append(r.pairs,
		replacement{"https://" + h, r.publicBase},
		replacement{"http://" + h, r.publicBase},
		replacement{"https:\\/\\/" + h, escaped},
		replacement{"http:\\/\\/" + h, escaped},
		replacement{"//" + h, "//" + r.publicHost},
		replacement{h, r.publicHost},
	)
}

// NeedsRewrite é o fast path: corpo sem nada sensível não passa por reescrita.
func (r *Rewriter) NeedsRewrite(chunk string) bool {
	low := strings.ToLower(chunk)
	for _, p := range r.pairs {
		if p.from != "" && strings.Contains(low, strings.ToLower(p.from)) {
			return true
		}
	}
	for _, c := range r.creds {
		if strings.Contains(chunk, c) {
			return true
		}
	}
	return false
}

// Line reescreve UMA linha (sem o \n). URLs nunca atravessam linha.
func (r *Rewriter) Line(line string) string {
	if line == "" {
		return line
	}
	// A porta é removida junto do host: "host:8080" -> "publico".
	for _, p := range r.pairs {
		if p.from == "" {
			continue
		}
		line = replaceFoldWithPort(line, p.from, p.to)
	}
	for _, c := range r.creds {
		line = strings.ReplaceAll(line, c, "")
	}
	if r.token != "" && line[0] != '#' &&
		strings.HasPrefix(strings.ToLower(line), strings.ToLower(r.publicBase)) &&
		!strings.Contains(line, "?t=") && !strings.Contains(line, "&t=") {
		sep := "?"
		if strings.Contains(line, "?") {
			sep = "&"
		}
		line += sep + "t=" + url.QueryEscape(r.token)
	}
	return line
}

// replaceFoldWithPort troca `from` (case-insensitive) por `to`, engolindo um
// ":porta" imediatamente após o host — senão sobraria ":8080" órfão na URL.
func replaceFoldWithPort(s, from, to string) string {
	lowS, lowFrom := strings.ToLower(s), strings.ToLower(from)
	var b strings.Builder
	i := 0
	for {
		j := strings.Index(lowS[i:], lowFrom)
		if j < 0 {
			b.WriteString(s[i:])
			return b.String()
		}
		j += i
		b.WriteString(s[i:j])
		b.WriteString(to)
		i = j + len(from)
		// engole :porta
		if i < len(s) && s[i] == ':' {
			k := i + 1
			for k < len(s) && s[k] >= '0' && s[k] <= '9' {
				k++
			}
			if k > i+1 {
				i = k
			}
		}
	}
}