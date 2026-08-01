// Package telemetry publica presence + heartbeat do nó.
//
// Duas vias, de propósito:
//  1. cdnv:lb:<id> no estado vivo — leitura barata para o caminho quente do
//     cérebro decidir o melhor músculo sem SSH nem score no request.
//  2. evento `heartbeat` no contrato — vira LbTelemetry::record(source=contract)
//     e alimenta o histórico/score no painel.
package telemetry

import (
	"os"
	"runtime"
	"strconv"
	"strings"
	"time"

	"github.com/aryperdomo123456789-web/cdnvoods/lb-go/internal/contract"
	"github.com/aryperdomo123456789-web/cdnvoods/lb-go/internal/events"
	"github.com/aryperdomo123456789-web/cdnvoods/lb-go/internal/state"
)

type Reporter struct {
	Contract *contract.Client
	State    *state.Store
	Events   *events.Queue
	Version  string
}

// Loop bate o coração para sempre. Nunca entra no caminho do stream.
func (rp *Reporter) Loop(every time.Duration) {
	t := time.NewTicker(every)
	defer t.Stop()
	for {
		rp.once(every)
		<-t.C
	}
}

func (rp *Reporter) once(every time.Duration) {
	snap := rp.Contract.Current()
	lbID := 0
	if snap != nil {
		lbID = snap.LB.ID
	}

	ramUsed, ramFree := memoryMB()
	payload := map[string]any{
		"engine":       "go",
		"version":      rp.Version,
		"cpu_pct":      loadPct(),
		"ram_used_mb":  ramUsed,
		"ram_free_mb":  ramFree,
		"goroutines":   runtime.NumGoroutine(),
		"state_degrad": rp.State.Degraded(),
	}
	ttl := int(every.Seconds())*3 + 15
	_ = rp.State.PresenceSet(lbID, payload, ttl)

	rp.Events.Push(events.Event{
		"type":        "heartbeat",
		"cpu_pct":     payload["cpu_pct"],
		"ram_used_mb": ramUsed,
		"ram_free_mb": ramFree,
		"engine":      "go",
	})
}

// loadPct usa load average normalizado por CPU — barato e suficiente para score.
func loadPct() float64 {
	raw, err := os.ReadFile("/proc/loadavg")
	if err != nil {
		return 0
	}
	fields := strings.Fields(string(raw))
	if len(fields) == 0 {
		return 0
	}
	load, err := strconv.ParseFloat(fields[0], 64)
	if err != nil {
		return 0
	}
	cpus := float64(runtime.NumCPU())
	if cpus <= 0 {
		cpus = 1
	}
	pct := load / cpus * 100
	if pct > 100 {
		pct = 100
	}
	return pct
}

func memoryMB() (float64, float64) {
	raw, err := os.ReadFile("/proc/meminfo")
	if err != nil {
		return 0, 0
	}
	var total, available float64
	for _, line := range strings.Split(string(raw), "\n") {
		fields := strings.Fields(line)
		if len(fields) < 2 {
			continue
		}
		kb, err := strconv.ParseFloat(fields[1], 64)
		if err != nil {
			continue
		}
		switch fields[0] {
		case "MemTotal:":
			total = kb / 1024
		case "MemAvailable:":
			available = kb / 1024
		}
	}
	return total - available, available
}