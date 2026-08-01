package proxy

import (
	"strings"
	"testing"

	"github.com/aryperdomo123456789-web/cdnvoods/lb-go/internal/contract"
)

func origin() *contract.Origin {
	return &contract.Origin{
		ID: 1, Scheme: "http", Host: "dafonte.uk", Port: 8080,
		HostHeader: "dafonte.uk", ExtraHosts: "cdn1.dafonte.uk",
		AuthUser: "segredoUser", AuthPass: "segredoPass", Active: true,
	}
}

func TestRewriteEscondeOrigemEPorta(t *testing.T) {
	rw := NewRewriter(origin(), "voods.suafontee.com", "http", "")
	got := rw.Line("http://dafonte.uk:8080/live/joao/123/1.ts")
	want := "http://voods.suafontee.com/live/joao/123/1.ts"
	if got != want {
		t.Fatalf("got %q want %q", got, want)
	}
}

func TestRewriteEscondeExtraHostEJsonEscapado(t *testing.T) {
	rw := NewRewriter(origin(), "voods.suafontee.com", "http", "")
	if out := rw.Line(`{"url":"http:\/\/cdn1.dafonte.uk\/x.m3u8"}`); strings.Contains(out, "dafonte.uk") {
		t.Fatalf("vazou host da origem: %s", out)
	}
}

func TestRewriteRemoveCredencialDaOrigem(t *testing.T) {
	rw := NewRewriter(origin(), "voods.suafontee.com", "http", "")
	out := rw.Line("http://dafonte.uk/get.php?username=segredoUser&password=segredoPass")
	if strings.Contains(out, "segredoUser") || strings.Contains(out, "segredoPass") {
		t.Fatalf("vazou credencial da origem: %s", out)
	}
}

func TestRewriteDirectSourceHostDescobertoEmRuntime(t *testing.T) {
	rw := NewRewriter(origin(), "voods.suafontee.com", "http", "")
	rw.AddHost("readyondemand.click")
	if out := rw.Line("https://readyondemand.click/hls/a.m3u8"); strings.Contains(out, "readyondemand") {
		t.Fatalf("vazou host de direct source: %s", out)
	}
}

func TestRewriteNaoTocaComentarioDeHls(t *testing.T) {
	rw := NewRewriter(origin(), "voods.suafontee.com", "http", "tok")
	if out := rw.Line("#EXTM3U"); out != "#EXTM3U" {
		t.Fatalf("alterou tag de playlist: %s", out)
	}
}

func TestRewriteAnexaTokenSoNaUrlPublica(t *testing.T) {
	rw := NewRewriter(origin(), "voods.suafontee.com", "http", "tok")
	out := rw.Line("http://dafonte.uk:8080/live/a/b/1.ts")
	if !strings.HasSuffix(out, "?t=tok") {
		t.Fatalf("token não anexado: %s", out)
	}
}