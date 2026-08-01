// Comando lb-go — MÚSCULO do CDN Voods.
//
// Ele NÃO tem banco, NÃO tem PHP e NÃO tem regra de negócio própria: lê o
// snapshot do contrato v1 (docs/CONTRATO_LB_V1.md), entrega o byte e devolve
// eventos para o cérebro. Trocar PHP por Go em um LB é trocar quem escuta a
// porta, nada mais.
package main

import (
	"flag"
	"log"
	"net/http"
	"os"
	"time"

	"github.com/aryperdomo123456789-web/cdnvoods/lb-go/internal/config"
	"github.com/aryperdomo123456789-web/cdnvoods/lb-go/internal/contract"
	"github.com/aryperdomo123456789-web/cdnvoods/lb-go/internal/events"
	"github.com/aryperdomo123456789-web/cdnvoods/lb-go/internal/proxy"
	"github.com/aryperdomo123456789-web/cdnvoods/lb-go/internal/state"
	"github.com/aryperdomo123456789-web/cdnvoods/lb-go/internal/telemetry"
)

// Version é injetada no build (bin/lb-go-build.sh).
var Version = "dev"

func main() {
	check := flag.Bool("check", false, "valida configuração e snapshot, e sai (canário)")
	flag.Parse()

	logger := log.New(os.Stdout, "", log.LstdFlags|log.LUTC)
	logf := func(format string, args ...any) { logger.Printf(format, args...) }

	cfg, err := config.Load()
	if err != nil {
		logger.Fatalf("[config] %v", err)
	}

	cc := contract.NewClient(cfg.BrainBaseURL, cfg.LBToken)
	st := state.New(cfg.RedisHost, cfg.RedisPort, cfg.RedisPass, cfg.RedisDB)
	q := events.NewQueue(cfg.BrainBaseURL, cfg.LBToken)

	if *check {
		if err := cc.Refresh(); err != nil {
			logger.Fatalf("[check] snapshot falhou: %v", err)
		}
		snap := cc.Current()
		logf("[check] ok lb=%d(%s) contrato=%s origens=%d aliases=%d usuarios=%d estado=%s degradado=%v",
			snap.LB.ID, snap.LB.Label, snap.ContractVersion,
			len(snap.Origins), len(snap.Aliases), len(snap.Users),
			snap.State.EffectiveDriver, st.Degraded())
		return
	}

	h := proxy.New(cc, st, q, cfg.PublicScheme, cfg.MaxHops, cfg.UpstreamTimeout, cfg.BrainBaseURL)
	h.Logf = logf

	go cc.Loop(cfg.SnapshotInterval, logf)
	go q.Loop(cfg.EventFlushEvery, logf)
	go (&telemetry.Reporter{Contract: cc, State: st, Events: q, Version: Version}).Loop(cfg.HeartbeatInterval)

	mux := http.NewServeMux()
	mux.HandleFunc("/healthz", h.Healthz)
	mux.Handle("/", h)

	srv := &http.Server{
		Addr:              cfg.Listen,
		Handler:           mux,
		ReadHeaderTimeout: 15 * time.Second,
		// Sem WriteTimeout: filme de 2h é uma escrita só.
		IdleTimeout: 60 * time.Second,
	}

	logf("[lb-go] %s ouvindo em %s cerebro=%s", Version, cfg.Listen, cfg.BrainBaseURL)
	if err := srv.ListenAndServe(); err != nil && err != http.ErrServerClosed {
		logger.Fatalf("[lb-go] %v", err)
	}
}
