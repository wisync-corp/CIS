# Identidade visual CIS v7 — aplicação no sistema

Fonte: `CIS_folha_de_marca_v7.png` (CIS – Credit Insight Shift). Os SVG oficiais estão em `frontend/src/assets/brand/` e `frontend/public/`; texto já convertido em contornos (não dependem da Montserrat).

| Local | Versão da folha | Ficheiro |
|---|---|---|
| Página de login (painel claro) | Logótipo principal para fundo claro (260 px) | `cis-principal-claro.svg` |
| Login em telemóvel (fundo branco) | Compacta, fundo claro (150 px) | `cis-compacta-claro.svg` |
| Menu lateral expandido (claro) | Compacta para fundo claro, sem fundo próprio (170 px) | `cis-compacta-claro-transparente.svg` |
| Cabeçalho do dashboard (≤750 px, menu escondido) | Compacta, fundo claro (120 px) | `cis-compacta-claro.svg` |
| Favicon | Favicon simplificado (sem contorno) + PNG 16/32 | `public/favicon.svg`, `favicon-16/32.png` |
| Aplicação móvel / ecrã inicial | Ícone com fundo roxo | `apple-touch-icon.png`, `icon-192/512.png`, `site.webmanifest` |
| Ecrã de carregamento | Símbolo isolado animado | inline em `index.html`; também em `.cis-loading` |

Regras mantidas: sem sombras, gradientes ou distorção nos logótipos (só `width` com `height:auto`); margem de protecção já incluída nos SVG; os três temas da interface têm menu e painel de login escuros, por isso usam as versões para fundo escuro. O verde de apoio `#8CCB34` é só para a interface, nunca no logótipo.

Menu lateral recolhido (só ícones): usa `cis-simbolo-claro.svg`, o símbolo isolado extraído sem alterações do `cis-compacta-claro-transparente.svg`. Pendente: relatórios/documentos impressos (versão fundo claro) quando forem criados.

## Temas da interface — três versões claras de alto contraste

Decisão (18/11/2026): os três temas usam menu e entrada claros com o **logótipo alternativo para fundo claro**. As versões para fundo escuro ficam no pacote da marca para outros suportes.

| Tema (id) | Menu / login | Acções | Contraste das acções (branco) |
|---|---|---|---|
| **CIS Branco** (`fintech`, predefinido) | `#FFFFFF` | roxo `#452D80` | 10,8:1 |
| **CIS Verde** (`banking`) | verde-claro `#F2F8EA` | verde `#2A520B` | 9,1:1 |
| **CIS Lilás** (`premium`) | lilás `#EFEBF8` | roxo `#342163` | 13,6:1 |

Regras de contraste aplicadas: texto principal ≥ 16:1; texto secundário ≥ 8:1; itens do menu ≥ 9,9:1; acções ≥ 7:1 também sobre os fundos tingidos (AAA); contorno de campos ≥ 3,2:1 com foco visível; indicadores e separador activo em verde `#3B7311` (≥ 4,9:1). Lima `#B5FA32` só nos heróis roxos dos módulos (14,6:1), nunca em superfícies claras. Equilíbrio 60·30·10 mantido (neutros · roxo da marca · verde para estados activos e positivos). A preferência antiga `claro` passa automaticamente para CIS Branco.

`cis-compacta-claro-transparente.svg` é o SVG oficial da versão compacta clara sem o rectângulo branco de fundo (mesmo desenho, para assentar em superfícies claras não brancas).
