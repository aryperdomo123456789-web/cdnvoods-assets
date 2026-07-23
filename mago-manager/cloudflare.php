<?php
/**
 * ═══════════════════════════════════════════════════════════════════
 * MAGO GATEWAY V2 - Cloudflare API V4 Integration
 * ═══════════════════════════════════════════════════════════════════
 * Classe para gerenciar registros DNS na Cloudflare
 * API Documentation: https://developers.cloudflare.com/api/
 * ═══════════════════════════════════════════════════════════════════
 */

require_once '../config.php';

class CloudflareAPI {
    private $email;
    private $zoneId;
    private $apiToken;
    private $apiUrl;

    public function __construct() {
        $this->email = CLOUDFLARE_EMAIL;
        $this->zoneId = CLOUDFLARE_ZONE_ID;
        $this->apiToken = CLOUDFLARE_API_TOKEN;
        $this->apiUrl = CLOUDFLARE_API_URL;
    }

    /**
     * Realiza requisição à API da Cloudflare
     */
    private function request($method, $endpoint, $data = null) {
        $url = $this->apiUrl . $endpoint;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        $headers = [
            'Authorization: Bearer ' . $this->apiToken,
            'Content-Type: application/json'
        ];

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return [
                'success' => false,
                'error' => 'Erro cURL: ' . $error
            ];
        }

        $result = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300 && isset($result['success']) && $result['success']) {
            return [
                'success' => true,
                'data' => $result['result'] ?? null
            ];
        } else {
            return [
                'success' => false,
                'error' => $result['errors'][0]['message'] ?? 'Erro desconhecido na API Cloudflare',
                'http_code' => $httpCode
            ];
        }
    }

    /**
     * Cria um registro DNS tipo A (obrigatoriamente com proxied: false)
     *
     * @param string $dominio Nome do domínio (ex: "api.exemplo.com")
     * @param string $ip Endereço IP de destino
     * @return array Resposta da API
     */
    public function criarRegistroA($dominio, $ip) {
        // Remove porta do IP caso exista
        $ipLimpo = explode(':', $ip)[0];

        $data = [
            'type' => 'A',
            'name' => $dominio,
            'content' => $ipLimpo,
            'ttl' => 1, // 1 = Automatic
            'proxied' => false // OBRIGATÓRIO: Somente DNS (Nuvem Cinza)
        ];

        $endpoint = "/zones/{$this->zoneId}/dns_records";
        return $this->request('POST', $endpoint, $data);
    }

    /**
     * Busca um registro DNS por nome
     *
     * @param string $dominio Nome do domínio
     * @return array|null Dados do registro ou null se não encontrado
     */
    public function buscarRegistro($dominio) {
        $endpoint = "/zones/{$this->zoneId}/dns_records?type=A&name={$dominio}";
        $result = $this->request('GET', $endpoint);

        if ($result['success'] && !empty($result['data'])) {
            return $result['data'][0];
        }

        return null;
    }

    /**
     * Deleta um registro DNS
     *
     * @param string $recordId ID do registro
     * @return array Resposta da API
     */
    public function deletarRegistro($recordId) {
        $endpoint = "/zones/{$this->zoneId}/dns_records/{$recordId}";
        return $this->request('DELETE', $endpoint);
    }

    /**
     * Atualiza um registro DNS existente
     *
     * @param string $recordId ID do registro
     * @param string $dominio Nome do domínio
     * @param string $ip Novo IP
     * @return array Resposta da API
     */
    public function atualizarRegistro($recordId, $dominio, $ip) {
        $ipLimpo = explode(':', $ip)[0];

        $data = [
            'type' => 'A',
            'name' => $dominio,
            'content' => $ipLimpo,
            'ttl' => 1,
            'proxied' => false
        ];

        $endpoint = "/zones/{$this->zoneId}/dns_records/{$recordId}";
        return $this->request('PUT', $endpoint, $data);
    }

    /**
     * Valida se o domínio e IP estão no formato correto
     *
     * @param string $dominio
     * @param string $ip
     * @return array ['valido' => bool, 'erro' => string]
     */
    public function validarDados($dominio, $ip) {
        // Valida domínio
        if (empty($dominio) || !preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/', $dominio)) {
            return ['valido' => false, 'erro' => 'Domínio inválido'];
        }

        // Valida IP (extrai IP caso tenha porta)
        $ipLimpo = explode(':', $ip)[0];
        if (!filter_var($ipLimpo, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return ['valido' => false, 'erro' => 'IP inválido'];
        }

        return ['valido' => true];
    }
}
