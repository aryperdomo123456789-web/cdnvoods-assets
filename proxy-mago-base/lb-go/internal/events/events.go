// Package events empurra o que aconteceu no músculo para o cérebro em LOTE.
//
// Nunca no caminho quente: o handler só enfileira (não-bloqueante). Se a fila
// enche, o evento mais antigo é descartado — perder telemetria é aceitável,
// travar player não é.
package events

import (
	"bytes"
	"encoding/json"
	"net/http"
	"sync"
	"time"
)

const MaxBatch = 500

type Event map[string]any

type Queue struct {
	url   string
	token string
	http  *http.Client

	mu      sync.Mutex
	buf     []Event
	dropped int64
	sent    int64
	failed  int64
	cap     int
}

func NewQueue(brainBaseURL, token string) *Queue {
	return &Queue{
		url:   brainBaseURL + "/lb-events.php",
		token: token,
		http:  &http.Client{Timeout: 10 * time.Second},
		cap:   20000,
	}
}

func (q *Queue) Push(e Event) {
	q.mu.Lock()
	defer q.mu.Unlock()
	if len(q.buf) >= q.cap {
		q.buf = q.buf[1:]
		q.dropped++
	}
	q.buf = append(q.buf, e)
}

func (q *Queue) take() []Event {
	q.mu.Lock()
	defer q.mu.Unlock()
	if len(q.buf) == 0 {
		return nil
	}
	n := len(q.buf)
	if n > MaxBatch {
		n = MaxBatch
	}
	batch := q.buf[:n]
	q.buf = append([]Event(nil), q.buf[n:]...)
	return batch
}

func (q *Queue) requeue(batch []Event) {
	q.mu.Lock()
	defer q.mu.Unlock()
	q.failed++
	q.buf = append(batch, q.buf...)
	if len(q.buf) > q.cap {
		q.dropped += int64(len(q.buf) - q.cap)
		q.buf = q.buf[len(q.buf)-q.cap:]
	}
}

func (q *Queue) Stats() map[string]any {
	q.mu.Lock()
	defer q.mu.Unlock()
	return map[string]any{
		"pending": len(q.buf),
		"sent":    q.sent,
		"failed":  q.failed,
		"dropped": q.dropped,
	}
}

// Flush envia um lote. Falha => o lote volta para a fila (sem perder trilha).
func (q *Queue) Flush() error {
	batch := q.take()
	if batch == nil {
		return nil
	}
	body, err := json.Marshal(map[string]any{
		"contract_version": "1.0",
		"events":           batch,
	})
	if err != nil {
		return err
	}
	req, err := http.NewRequest(http.MethodPost, q.url, bytes.NewReader(body))
	if err != nil {
		q.requeue(batch)
		return err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-LB-Token", q.token)

	resp, err := q.http.Do(req)
	if err != nil {
		q.requeue(batch)
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		q.requeue(batch)
		return &httpError{resp.StatusCode}
	}
	q.mu.Lock()
	q.sent += int64(len(batch))
	q.mu.Unlock()
	return nil
}

type httpError struct{ code int }

func (e *httpError) Error() string { return "lb-events http " + itoa(e.code) }

func itoa(n int) string {
	if n == 0 {
		return "0"
	}
	var b [8]byte
	i := len(b)
	for n > 0 {
		i--
		b[i] = byte('0' + n%10)
		n /= 10
	}
	return string(b[i:])
}

// Loop drena a fila para sempre.
func (q *Queue) Loop(every time.Duration, logf func(string, ...any)) {
	t := time.NewTicker(every)
	defer t.Stop()
	for range t.C {
		for i := 0; i < 4; i++ {
			if err := q.Flush(); err != nil {
				logf("[events] flush falhou: %v", err)
				break
			}
		}
	}
}