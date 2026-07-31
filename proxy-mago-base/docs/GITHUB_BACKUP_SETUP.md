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

## Observacoes

- Arquivos de runtime SQLite e logs ficam fora do versionamento via `.gitignore`.
- O repositorio local esta configurado para usar apenas a chave SSH exclusiva neste diretorio.
