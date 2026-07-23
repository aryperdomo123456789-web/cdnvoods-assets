# Git Remote Setup

## Objetivo

Usar este repositório para editar localmente, versionar no GitHub e depois atualizar o servidor Ubuntu 22 com o mesmo conteúdo.

## Chave SSH gerada

- Arquivo privado: `id_github_cdnvoods`
- Arquivo público: `id_github_cdnvoods.pub`

## Como usar no GitHub

1. copie o conteúdo da chave pública
2. adicione como `Deploy key` no repositório `aryperdomo123456789-web/cdnvoods`
3. marque `Allow write access` se quiser fazer push com essa mesma chave

## Remote sugerido

```bash
git remote add origin git@github.com:aryperdomo123456789-web/cdnvoods.git
```

## Fluxo recomendado

1. editar localmente
2. `git add . && git commit -m "mensagem"`
3. `git push origin main`
4. no servidor, fazer `git pull`

## Observação

Se preferir separar permissões, use uma chave para push local e outra para pull no servidor.

