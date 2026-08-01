# Backup GitHub Setup

> **Fluxo oficial de publicação:** este servidor `45.140.192.237` publica primeiro em `aryperdomo123456789-web/cdnvoods-assets/tree/backup`. Somente depois a branch `backup` promove o mesmo conteúdo para `aryperdomo123456789-web/cdnvoods-assets/tree/main`. Nunca publicar deste servidor diretamente para `main`.


Este espelho usa o conteudo de `proxy-mago-base/` como base de trabalho.

## Chave SSH exclusiva

- Chave privada: `/opt/proxy-mago/keys/id_github_cdnvoods_assets`
- Chave publica: `/opt/proxy-mago/keys/id_github_cdnvoods_assets.pub`

## Fluxo

1. Cadastre a chave publica como acesso ao repositorio `aryperdomo123456789-web/cdnvoods-assets`.
2. Trabalhe neste espelho: `/opt/proxy-mago/backup/cdnvoods-assets`
3. Commit e push daqui para publicar no GitHub sem misturar com o repositorio raiz da VPS.

## Fluxo operacional versionado

No repositório principal (`/opt/proxy-mago`) o fluxo oficial agora está
automatizado:

1. instalar o bloqueio de push direto:
   - `bash proxy-mago-base/bin/install-git-hooks.sh`
2. publicar o commit atual primeiro em `assets/backup`:
   - `bash proxy-mago-base/bin/git-publish-backup.sh`
3. promover `backup -> main` só depois da bateria oficial:
   - `bash proxy-mago-base/bin/git-promote-backup-to-main.sh`

### O que cada script faz

- `git-publish-backup.sh`
  - exige worktree limpa
  - roda validação de release de `backup`
  - empurra `HEAD` para `assets/backup`

- `git-promote-backup-to-main.sh`
  - exige worktree limpa
  - busca `assets/backup`
  - monta worktree temporária exatamente no commit de `backup`
  - roda validação de `promote` (inclui `smoke-all.sh`)
  - só então faz fast-forward seguro de `assets/main`

- `git-validate-release.sh [backup|promote]`
  - `php -l` em `app/ public/ bin/ config`
  - `smoke-statestore.php`
  - `smoke-lb-only.php`
  - no perfil `promote`, também roda `smoke-all.sh`

### Proteção contra erro humano

O hook `.githooks/pre-push` bloqueia `git push assets ...main` por padrão.
Para furar conscientemente a trava:

- `ALLOW_PUSH_MAIN=1 git push assets ...`

## Observacoes

- Arquivos de runtime SQLite e logs ficam fora do versionamento via `.gitignore`.
- O repositorio local esta configurado para usar apenas a chave SSH exclusiva neste diretorio.
