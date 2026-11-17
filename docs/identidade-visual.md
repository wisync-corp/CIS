# Identidade visual CIS v7 — aplicação no sistema

Fonte: `CIS_folha_de_marca_v7.png` (CIS – Credit Insight Shift). Os SVG oficiais estão em `frontend/src/assets/brand/` e `frontend/public/`; texto já convertido em contornos (não dependem da Montserrat).

| Local | Versão da folha | Ficheiro |
|---|---|---|
| Página de login (painel escuro) | Logótipo principal, fundo escuro (≥240 px → 260 px); no tema CIS Claro, principal para fundo claro | `cis-principal-escuro.svg` / `cis-principal-claro.svg` |
| Login em telemóvel (fundo branco) | Compacta, fundo claro (150 px) | `cis-compacta-claro.svg` |
| Menu lateral expandido | Compacta, fundo escuro (120–239 px → 170 px); no tema CIS Claro, compacta para fundo claro | `cis-compacta-escuro.svg` / `cis-compacta-claro-transparente.svg` |
| Cabeçalho do dashboard (≤750 px, menu escondido) | Compacta, fundo claro (120 px) | `cis-compacta-claro.svg` |
| Favicon | Favicon simplificado (sem contorno) + PNG 16/32 | `public/favicon.svg`, `favicon-16/32.png` |
| Aplicação móvel / ecrã inicial | Ícone com fundo roxo | `apple-touch-icon.png`, `icon-192/512.png`, `site.webmanifest` |
| Ecrã de carregamento | Símbolo isolado animado | inline em `index.html`; também em `.cis-loading` |

Regras mantidas: sem sombras, gradientes ou distorção nos logótipos (só `width` com `height:auto`); margem de protecção já incluída nos SVG; os três temas da interface têm menu e painel de login escuros, por isso usam as versões para fundo escuro. O verde de apoio `#8CCB34` é só para a interface, nunca no logótipo.

Pendente: menu lateral recolhido (símbolo isolado) quando existir esse modo; relatórios/documentos impressos (versão fundo claro) quando forem criados.

## Temas da interface — equilíbrio de cores 60·30·10

- **60% neutros claros**: fundo, cartões e superfícies.
- **30% roxo da marca**: menu, herói, títulos (`#170F2B`) e acções (`--cis-primary`).
- **10% verde/lima**: estados activos (separador seleccionado), passos concluídos, saldo disponível, contas activas, indicador do menu.

Escala roxa derivada do matiz da marca (257°): 900 `#170F2B` títulos e superfícies escuras · 700 `#342163` hover · 600 `#452D80` acções, ícones e etiquetas (10,8:1 com branco) · tint `#F1EEF9`. Usar o 900 para acções deixava tudo quase preto e sem hierarquia.

Tokens de acento (iguais nos três temas): `--cis-accent #4F9A0F` (marcas gráficas em fundo claro, 3,5:1), `--cis-accent-ink #3B7311` (texto, 5,8:1), `--cis-accent-tint #EFF7E3`, `--cis-accent-line #CFE6B4`, `--cis-lime #B5FA32` (só sobre fundo escuro, 14,6:1). Lima puro sobre branco (1,26:1) nunca é usado.

| Tema (id) | Menu / login | Acções | Acento |
|---|---|---|---|
| **CIS Roxo** (`fintech`, predefinido) | `#170F2B` | `#452D80` | verde / lima |
| **CIS Verde** (`banking`) | `#170F2B` | `#3B7311` | verde / lima |
| **CIS Grafite** (`premium`) | `#1E1E22` | `#452D80` | verde / lima |
| **CIS Claro** (`claro`) | Branco `#FFFFFF` com **logótipo alternativo claro** (compacta e principal para fundo claro) | `#452D80` | verde (`#4F9A0F` / `#3B7311`; lima nunca sobre branco) |

Os verdes-azulados de estado (`#1e7953`, `#9ef0bc`, …) foram substituídos pelos verdes da marca para não haver dois verdes em conflito. Avisos (âmbar) e erros (vermelho) mantêm-se.

`cis-compacta-claro-transparente.svg` é o SVG oficial da versão compacta clara sem o rectângulo branco de fundo (mesmo desenho, para assentar em superfícies claras não brancas).
