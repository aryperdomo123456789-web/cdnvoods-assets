// Package config carrega a configuração do músculo Go.
//
// Regra: NADA de regra de negócio aqui. O motor só precisa saber como falar
// com o cérebro (URL + token) e com o estado vivo (Redis). Todo o resto vem do
// snapshot do contrato v1 (docs/CONTRATO_LB_V1.md).
package config

import (
	"errors"
	"os"
	"strconv"
	"strings"
	"time"
)

type Config struct {
	Listen string // ex.: 127.0.0.1:8081 (Nginx na frente)

	BrainBaseURL string // ex.: https://painel.exemplo.com
	LBToken      string // X-LB-Token do nó em lb_nodes

	RedisHost string
	RedisPort int
	RedisPass string
	RedisDB   int

	PublicScheme string // esquema das URLs reescritas na playlist
	BrainDirectFetchHosts string

	SnapshotInterval  time.Duration
	EventFlushEvery   time.Duration
	HeartbeatInterval time.Duration
	UpstreamTimeout   time.Duration
	MaxHops           int
}

func env(key, def string) string {
	if v := strings.TrimSpace(os.Getenv(key)); v != "" {
		return v
	}
	return def
}

func envInt(key string, def int) int {
	if n, err := strconv.Atoi(env(key, "")); err == nil {
		return n
	}
	return def
}

// Load lê o ambiente (o instalador escreve /etc/cdnvoods-lb.env).
func Load() (Config, error) {
	c := Config{
		Listen:            env("LB_LISTEN", "127.0.0.1:8081"),
		BrainBaseURL:      strings.TrimRight(env("LB_BRAIN_URL", ""), "/"),
		LBToken:           env("LB_TOKEN", ""),
		RedisHost:         env("LB_REDIS_HOST", "127.0.0.1"),
		RedisPort:         envInt("LB_REDIS_PORT", 6379),
		RedisPass:         env("LB_REDIS_PASS", ""),
		RedisDB:           envInt("LB_REDIS_DB", 0),
		PublicScheme:      env("LB_PUBLIC_SCHEME", "http"),
		BrainDirectFetchHosts: env("LB_BRAIN_DIRECT_FETCH_HOSTS", ""),
		SnapshotInterval:  time.Duration(envInt("LB_SNAPSHOT_SECONDS", 30)) * time.Second,
		EventFlushEvery:   time.Duration(envInt("LB_EVENT_FLUSH_SECONDS", 5)) * time.Second,
		HeartbeatInterval: time.Duration(envInt("LB_HEARTBEAT_SECONDS", 30)) * time.Second,
		UpstreamTimeout:   time.Duration(envInt("LB_UPSTREAM_TIMEOUT_SECONDS", 45)) * time.Second,
		MaxHops:           envInt("LB_MAX_HOPS", 12),
	}

	if c.BrainBaseURL == "" {
		return c, errors.New("LB_BRAIN_URL obrigatório (URL do cérebro/painel)")
	}
	if c.LBToken == "" {
		return c, errors.New("LB_TOKEN obrigatório (token do nó em lb_nodes)")
	}
	if c.PublicScheme != "https" {
		c.PublicScheme = "http"
	}
	return c, nil
}
