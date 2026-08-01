package contract

import (
	"encoding/json"
	"testing"
	"time"
)

const raw = `{"ok":true,"contract":"cdnvoods.lb","contract_version":"1.0","ttl":30,
 "lb":{"id":2,"label":"LB-02","enabled":true,"drain":false},
 "state":{"driver":"redis","effective_driver":"redis","degraded":false,"namespace":"cdnv:"},
 "runtime":{"sessions_enabled":true,"enforce_ip_lock":true,"enforce_connection_limit":true,
   "session_ttl_live":120,"session_ttl_vod":1800,"lb_require_delivery":true,"lb_default_mode":"auto"},
 "origins":[{"id":1,"scheme":"http","host":"dafonte.uk","port":8080,"active":true}],
 "aliases":[{"id":1,"hostname":"voods.suafontee.com","origin_id":1,"active":true}],
 "users":[{"username":"joao","max_connections":2,"exp_date":"0",
   "allowed_ips":["45.140.192.0/24","10.0.0.7"],"ip_locked":true},
  {"username":"velho","max_connections":1,"exp_date":"2020-01-01 00:00:00","allowed_ips":[],"ip_locked":false}],
 "campo_desconhecido_do_futuro":{"x":1}}`

func load(t *testing.T) *Snapshot {
	t.Helper()
	var s Snapshot
	if err := json.Unmarshal([]byte(raw), &s); err != nil {
		t.Fatalf("snapshot não decodificou: %v", err)
	}
	s.index()
	return &s
}

func TestCampoDesconhecidoNaoEFatal(t *testing.T) {
	s := load(t)
	if s.ContractVersion != "1.0" || s.LB.ID != 2 {
		t.Fatalf("snapshot inconsistente: %+v", s.LB)
	}
}

func TestResolveOriginPorAlias(t *testing.T) {
	s := load(t)
	if o := s.ResolveOrigin("voods.suafontee.com:80"); o == nil || o.Host != "dafonte.uk" {
		t.Fatalf("alias não resolveu para a origem: %+v", o)
	}
}

func TestTravaDeIpComCidr(t *testing.T) {
	s := load(t)
	u, ok := s.User("JOAO")
	if !ok {
		t.Fatal("usuário não indexado (case-insensitive)")
	}
	if !u.IPAllowed("45.140.192.9") {
		t.Fatal("CIDR permitido foi recusado")
	}
	if !u.IPAllowed("10.0.0.7") {
		t.Fatal("IP exato permitido foi recusado")
	}
	if u.IPAllowed("8.8.8.8") {
		t.Fatal("IP de fora passou pela trava")
	}
}

func TestExpiracaoDeAssinatura(t *testing.T) {
	s := load(t)
	now := time.Now()
	joao, _ := s.User("joao")
	if joao.Expired(now) {
		t.Fatal("exp_date=0 não deve expirar")
	}
	velho, _ := s.User("velho")
	if !velho.Expired(now) {
		t.Fatal("assinatura de 2020 deveria estar expirada")
	}
}

func TestMajorIncompativel(t *testing.T) {
	if versionCompatible("2.0") {
		t.Fatal("major 2 não pode ser aceito pelo motor v1")
	}
	if !versionCompatible("1.7") {
		t.Fatal("minor maior no mesmo major deve ser aceito")
	}
}