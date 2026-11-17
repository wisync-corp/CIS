# Identidade visual CIS v7 — aplicação no sistema

Fonte: `CIS_folha_de_marca_v7.png` (CIS – Credit Insight Shift). Os SVG oficiais estão em `frontend/src/assets/brand/` e `frontend/public/`; texto já convertido em contornos (não dependem da Montserrat).

| Local | Versão da folha | Ficheiro |
|---|---|---|
| Página de login (painel escuro) | Logótipo principal, fundo escuro (≥240 px → 260 px) | `cis-principal-escuro.svg` |
| Login em telemóvel (fundo branco) | Compacta, fundo claro (150 px) | `cis-compacta-claro.svg` |
| Menu lateral expandido | Compacta, fundo escuro (120–239 px → 170 px) | `cis-compacta-escuro.svg` |
| Cabeçalho do dashboard (≤750 px, menu escondido) | Compacta, fundo claro (120 px) | `cis-compacta-claro.svg` |
| Favicon | Favicon simplificado (sem contorno) + PNG 16/32 | `public/favicon.svg`, `favicon-16/32.png` |
| Aplicação móvel / ecrã inicial | Ícone com fundo roxo | `apple-touch-icon.png`, `icon-192/512.png`, `site.webmanifest` |
| Ecrã de carregamento | Símbolo isolado animado | inline em `index.html`; também em `.cis-loading` |

Regras mantidas: sem sombras, gradientes ou distorção nos logótipos (só `width` com `height:auto`); margem de protecção já incluída nos SVG; os três temas da interface têm menu e painel de login escuros, por isso usam as versões para fundo escuro. O verde de apoio `#8CCB34` é só para a interface, nunca no logótipo.

Pendente: menu lateral recolhido (símbolo isolado) quando existir esse modo; relatórios/documentos impressos (versão fundo claro) quando forem criados.

## Temas da interface (alinhados à marca)

Os três temas mantêm os identificadores antigos (preferências guardadas continuam válidas) e passam a usar a paleta v7:

| Tema (id) | Menu / login | Acções e destaques em fundo claro | Destaque em fundo escuro |
|---|---|---|---|
| **CIS Roxo** (`fintech`, predefinido) | Roxo profundo `#170F2B` | Roxo `#170F2B` | Lima `#B5FA32` |
| **CIS Verde** (`banking`) | Roxo profundo `#170F2B` | Texto verde `#3B7311` | Lima `#B5FA32` |
| **CIS Grafite** (`premium`) | Grafite `#1E1E22` (escala de cinzentos da marca) | Roxo `#170F2B` | Lima `#B5FA32` |

Contraste (WCAG): lima sobre roxo 14,6:1; branco sobre roxo 18,4:1; branco sobre `#3B7311` 5,8:1. Lima puro sobre branco tem 1,26:1 — por isso nunca é usado como texto ou botão em fundo claro. Os heróis do Simulador e da Cobrança deixaram de ter verde-azulado fixo e seguem o tema. Cores semânticas de estado (sucesso, aviso, erro) mantêm-se.
