"""CIS · Dashboard de microcrédito (Streamlit, dentro do CIS).

Três painéis do Dashboard original, com as mesmas ideias e a aparência do CIS:
  1. Carteira   – desembolsos, carteira em curso, inadimplência e faixas de crédito;
  2. Mutuários  – clientes, novos clientes, retenção, aquisição e estado civil;
  3. Financeiro – prestações recebidas e por receber, juros, taxa efectiva e crescimento.

Os dados vêm da API do CIS (/api/streamlit/dados), que valida a sessão, a permissão
«Relatórios», a agência e a carteira do utilizador. Cada linha é uma prestação.
"""
from __future__ import annotations

import html
import os
from datetime import datetime
from zoneinfo import ZoneInfo

import pandas as pd
import plotly.express as px
import plotly.graph_objects as go
import requests
import streamlit as st

TZ = ZoneInfo("Africa/Maputo")
API_URL = os.environ.get("CIS_API_URL", "http://api-preview:8000/api/streamlit/dados")

st.set_page_config(page_title="CIS · Dashboard", page_icon=":material/monitoring:", layout="wide",
                   initial_sidebar_state="collapsed")

# ---------------------------------------------------------------------------
# Identidade visual (mesmos tokens de frontend/src/themes.css)
# ---------------------------------------------------------------------------
BASE = dict(text="#1F1A2E", heading="#170F2B", accent="#3B7311", accent_tint="#EFF7E3",
            danger="#B42318", danger_tint="#FEF0EE", warn="#B54708", warn_tint="#FFF6E5")
THEMES = {
    "fintech": dict(BASE, primary="#452D80", tint="#EEEAF7", bg="#F7F6FA", subtle="#F4F2F9", muted="#4A4360", border="#D9D3E6"),
    "banking": dict(BASE, primary="#2A520B", tint="#E6F2D6", bg="#F7FAF3", subtle="#F2F7EB", muted="#45503C", border="#D3DEC6"),
    "premium": dict(BASE, primary="#342163", tint="#E6E0F4", bg="#F7F5FB", subtle="#F2EFF9", muted="#4A4360", border="#D6CFE6"),
}
T = THEMES.get(str(st.query_params.get("tema", "fintech")), THEMES["fintech"])
PALETTE = [T["primary"], "#3B7311", "#8B6FD6", "#C08A1E", "#2D7F8C", "#B4537E", "#6B7A8F", "#7FB23A", "#5A3FA8", "#A3A3C2"]

MESES = ["Jan", "Fev", "Mar", "Abr", "Mai", "Jun", "Jul", "Ago", "Set", "Out", "Nov", "Dez"]
MESES_LONGOS = ["Janeiro", "Fevereiro", "Março", "Abril", "Maio", "Junho", "Julho", "Agosto",
                "Setembro", "Outubro", "Novembro", "Dezembro"]
FAIXAS = ["Até 5 000", "5 001 - 25 000", "25 001 - 100 000", "Acima de 100 000"]


def rgba(hex_color: str, alpha: float) -> str:
    h = hex_color.lstrip("#")
    return f"rgba({int(h[0:2], 16)},{int(h[2:4], 16)},{int(h[4:6], 16)},{alpha})"


def css() -> None:
    st.html(f"""
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@600;700;800&display=swap');
:root {{ --p:{T['primary']}; --tint:{T['tint']}; --bg:{T['bg']}; --subtle:{T['subtle']}; --muted:{T['muted']};
  --line:{T['border']}; --text:{T['text']}; --head:{T['heading']}; --ok:{T['accent']}; --okt:{T['accent_tint']};
  --bad:{T['danger']}; --badt:{T['danger_tint']}; }}
html, body, .stApp, [data-testid="stAppViewContainer"] {{ background: var(--bg) !important; color: var(--text); font-family: 'DM Sans', system-ui, sans-serif; }}
header[data-testid="stHeader"], [data-testid="stToolbar"], [data-testid="stDecoration"], footer, #MainMenu {{ display: none !important; }}
.block-container, [data-testid="stMainBlockContainer"] {{ padding: 2px 2px 12px !important; max-width: none; }}
[data-testid="stMain"], section.main {{ overflow: hidden; }}
h1, h2, h3, h4 {{ font-family: 'Manrope', sans-serif !important; color: var(--head) !important; letter-spacing: -.3px; }}
.cis-kicker {{ color: var(--p); letter-spacing: 1.8px; font-size: 10.5px; font-weight: 800; text-transform: uppercase; }}
.cis-head {{ display:flex; align-items:baseline; gap:12px; flex-wrap:wrap; margin: 2px 0 -6px; }}
.cis-head h2 {{ margin: 0; font: 800 19px Manrope, sans-serif; color: var(--head); }}
.cis-head p {{ margin: 0; color: var(--muted); font-size: 12.5px; }}
.cis-sec {{ display:flex; align-items:baseline; justify-content:space-between; gap:12px; margin: 14px 0 8px; flex-wrap: wrap; }}
.cis-sec h3 {{ margin:0; font: 800 16px Manrope, sans-serif; color: var(--head); }}
.cis-sec span {{ color: var(--muted); font-size: 12.5px; }}
.cis-kpis {{ display:grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 14px; }}
.cis-kpi {{ background:#fff; border:1px solid var(--line); border-radius:14px; box-shadow:0 5px 24px #170f2b10; padding:13px 16px; min-width:0; }}
.cis-kpi .l {{ display:flex; justify-content:space-between; gap:6px; color: var(--muted); font-size:12px; font-weight:700; }}
.cis-kpi .l i {{ font-style:normal; color: var(--p); }}
.cis-kpi .v {{ font: 800 23px Manrope, sans-serif; color: var(--head); margin: 6px 0 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }}
.cis-kpi .d {{ display:inline-flex; align-items:center; gap:4px; font-size:11.5px; font-weight:700; border-radius:99px; padding:2px 8px; }}
.cis-kpi .d.up {{ background: var(--okt); color: var(--ok); }} .cis-kpi .d.down {{ background: var(--badt); color: var(--bad); }}
.cis-kpi .d.flat {{ background: var(--subtle); color: var(--muted); }}
.cis-kpi small {{ display:block; color: var(--muted); font-size: 11.5px; margin-top: 6px; line-height: 1.45; }}
.cis-kpi.hl {{ background: linear-gradient(135deg, var(--tint), #fff 70%); border-color: color-mix(in srgb, var(--p) 25%, var(--line)); }}
.cis-kpi.alert {{ border-color: #F5C2BC; background: linear-gradient(135deg, #FFF5F3, #fff 70%); }}
.cis-empty {{ border:1px dashed var(--line); border-radius:14px; padding:28px; text-align:center; color: var(--muted); background: #fff; }}
.cis-foot {{ color: var(--muted); font-size: 11.5px; margin-top: 18px; }}
[class*="st-key-panel"] {{ background:#fff; border:1px solid var(--line) !important; border-radius:14px !important; box-shadow:0 5px 24px #170f2b10; padding: 6px 6px 0 !important; }}
[class*="st-key-bar"] {{ background:#fff; border:1px solid var(--line); border-radius:14px; padding: 10px 14px; box-shadow:0 5px 24px #170f2b0a; }}
[data-testid="stSegmentedControl"] button[aria-checked="true"], [data-testid="stBaseButton-segmented_controlActive"] {{ background: var(--p) !important; color:#fff !important; border-color: var(--p) !important; }}
[data-testid="stExpander"] details {{ background:#fff; border:1px solid var(--line); border-radius:12px; }}
{icon_css()}
</style>""")


# ---------------------------------------------------------------------------
# Formatação (pt-MZ: espaço nos milhares, vírgula nas décimas)
# ---------------------------------------------------------------------------
def num(v: float, dec: int = 0) -> str:
    return f"{v:,.{dec}f}".replace(",", " ").replace(".", ",")


def mzn(v: float, dec: int = 2) -> str:
    return num(v, dec) + " MT"


def mzn_short(v: float) -> str:
    a = abs(v)
    if a >= 1_000_000:
        return num(v / 1_000_000, 2) + " M MT"
    if a >= 100_000:
        return num(v / 1_000, 1) + " mil MT"
    return mzn(v)


def pct(v: float | None, dec: int = 1) -> str:
    return "—" if v is None or pd.isna(v) else num(v, dec) + " %"


def ratio(a: float, b: float) -> float | None:
    return None if not b else a / b * 100


def delta(cur: float | None, prev: float | None, good_up: bool = True, points: bool = False):
    """(texto, classe) para a variação face ao período anterior."""
    if cur is None or prev is None or pd.isna(cur) or pd.isna(prev):
        return None
    if points:
        d = cur - prev
        txt = ("+" if d > 0 else "") + num(d, 1) + " p.p."
    else:
        if prev == 0:
            return None
        d = (cur - prev) / abs(prev) * 100
        txt = ("+" if d > 0 else "") + num(d, 1) + " %"
    if abs(d) < 0.05:
        return ("= 0,0" + (" p.p." if points else " %"), "flat")
    up = d > 0
    return (("▲ " if up else "▼ ") + txt.lstrip("+-"), "up" if up == good_up else "down")


_SVG = {  # ícones lucide (os mesmos do CIS)
    "contratos": '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8M16 17H8M10 9H8"/>',
    "dinheiro": '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/>',
    "media": '<path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/>',
    "juros": '<line x1="19" x2="5" y1="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
    "alerta": '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4M12 17h.01"/>',
    "prestacoes": '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
    "risco": '<path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/>',
    "pessoas": '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
    "novo": '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/>',
    "repete": '<path d="m17 2 4 4-4 4"/><path d="M3 11v-1a4 4 0 0 1 4-4h14"/><path d="m7 22-4-4 4-4"/><path d="M21 13v1a4 4 0 0 1-4 4H3"/>',
    "cresce": '<path d="m22 7-8.5 8.5-5-5L2 17"/><path d="M16 7h6v6"/>',
    "recebido": '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
    "relogio": '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
}


def icon_css() -> str:
    """O st.html não aceita <svg>: os ícones entram como máscaras CSS (cor = cor do texto)."""
    from urllib.parse import quote
    out = [".cis-kpi .l i{width:16px;height:16px;flex:none;background:currentColor;-webkit-mask:var(--ic) center/contain no-repeat;mask:var(--ic) center/contain no-repeat}"]
    for name, path in _SVG.items():
        svg = ('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="black" stroke-width="2" '
               f'stroke-linecap="round" stroke-linejoin="round">{path}</svg>')
        out.append(f'.ic-{name}{{--ic:url("data:image/svg+xml,{quote(svg)}")}}')
    return "\n".join(out)


def kpi(label: str, value: str, icon: str = "", d=None, note: str = "", cls: str = "") -> str:
    dh = f'<span class="d {d[1]}">{html.escape(d[0])}</span>' if d else ""
    nh = f"<small>{html.escape(note)}</small>" if note else ""
    return (f'<div class="cis-kpi {cls}"><div class="l"><span>{html.escape(label)}</span><i class="ic-{icon}"></i></div>'
            f'<div class="v" title="{html.escape(value)}">{html.escape(value)}</div>{dh}{nh}</div>')


def kpi_row(cards: list[str]) -> None:
    st.html('<div class="cis-kpis">' + "".join(cards) + "</div>")


def section(title: str, sub: str = "") -> None:
    st.html(f'<div class="cis-sec"><h3>{html.escape(title)}</h3><span>{html.escape(sub)}</span></div>')


def empty(msg: str) -> None:
    st.html(f'<div class="cis-empty">{html.escape(msg)}</div>')


def short(v: float, kind: str) -> str:
    """Rótulo curto para os valores nos gráficos."""
    if v is None or pd.isna(v):
        return ""
    if kind == "pct":
        return num(v, 1) + " %"
    if kind == "n":
        return num(v)
    a = abs(v)
    if a >= 1_000_000:
        return num(v / 1_000_000, 1) + " M"
    if a >= 1_000:
        return num(v / 1_000, 1) + " mil"
    return num(v)


def data_labels(fig: go.Figure, kind: str) -> None:
    """Rótulos a negrito nos próprios dados (substituem as linhas de grelha e o eixo dos valores)."""
    stacked = fig.layout.barmode == "stack"
    if stacked:
        fig.update_layout(uniformtext=dict(minsize=10, mode="hide"))
    for tr in fig.data:
        if tr.type == "bar":
            horiz = tr.orientation == "h"
            vals = list(tr.x if horiz else tr.y)
            tr.text = [f"<b>{short(v, kind)}</b>" if v else "" for v in vals]
            tr.texttemplate = "%{text}"
            tr.textposition = "inside" if stacked else "outside"
            tr.insidetextanchor = "middle"
            tr.textfont = dict(family="DM Sans", size=12, color=(T["heading"] if tr.name == "Por receber" else "#fff") if stacked else T["heading"])
            tr.cliponaxis = False
        elif tr.type == "scatter":
            tr.mode = "lines+markers+text"
            tr.text = [f"<b>{short(v, kind)}</b>" for v in tr.y]
            tr.texttemplate = "%{text}"
            tr.textposition = "top center"
            tr.textfont = dict(family="DM Sans", size=11.5, color=T["heading"])
            tr.cliponaxis = False
        elif tr.type == "pie":
            tr.texttemplate = "<b>%{percent}</b>"
            tr.textfont = dict(family="DM Sans", size=12)


def style(fig: go.Figure, title: str, height: int = 340, legend: bool = False, kind: str = "mt") -> go.Figure:
    fig.update_layout(
        title=dict(text=f"<b>{title}</b>", font=dict(family="Manrope, sans-serif", size=15, color=T["heading"]), x=0.01, y=0.97),
        font=dict(family="DM Sans, sans-serif", size=12, color=T["text"]), separators=", ",
        paper_bgcolor="rgba(0,0,0,0)", plot_bgcolor="rgba(0,0,0,0)", height=height,
        margin=dict(l=8, r=12, t=52, b=12), colorway=PALETTE, showlegend=legend,
        legend=dict(orientation="h", y=-0.12, x=0, font=dict(size=11)),
        hoverlabel=dict(bgcolor="#fff", bordercolor=T["border"], font=dict(family="DM Sans", color=T["text"])),
        bargap=0.28)
    fig.update_xaxes(showgrid=False, zeroline=False, linecolor=T["border"], tickfont=dict(color=T["muted"], size=12), title=None, automargin=True)
    fig.update_yaxes(showgrid=False, zeroline=False, showline=False, tickfont=dict(color=T["text"], size=12), title=None, automargin=True)
    data_labels(fig, kind)
    horiz = any(getattr(tr, "orientation", None) == "h" for tr in fig.data)
    # O eixo dos valores fica só com os rótulos dos dados (sem linhas nem números repetidos).
    if horiz:
        fig.update_xaxes(showticklabels=False, showline=False)
        fig.update_layout(margin=dict(r=72))
    elif any(tr.type in ("bar", "scatter") for tr in fig.data):
        fig.update_yaxes(showticklabels=False)
        fig.update_layout(margin=dict(t=64, l=28, r=28))
    return fig


def chart(fig: go.Figure, key: str) -> None:
    with st.container(key=f"panel_{key}"):
        st.plotly_chart(fig, width="stretch", theme=None, config={"displaylogo": False,
                        "modeBarButtonsToRemove": ["lasso2d", "select2d", "autoScale2d"]})


# ---------------------------------------------------------------------------
# Dados
# ---------------------------------------------------------------------------
@st.cache_data(ttl=300, max_entries=64, show_spinner=False)
def fetch(cookie: str, nonce: int) -> dict:
    r = requests.get(API_URL, cookies={"cis_streamlit": cookie}, headers={"Accept": "application/json"},
                     timeout=30, allow_redirects=False)
    r.raise_for_status()
    return r.json()


def prepare(data: dict) -> tuple[pd.DataFrame, pd.DataFrame, pd.DataFrame]:
    p = pd.DataFrame.from_records(data.get("contractos", []))
    if p.empty:
        return p, p, p
    for f in ("Quantia Creditada", "Valor Pago", "Valor a Pagar", "Valor de Juros"):
        p[f] = pd.to_numeric(p.get(f), errors="coerce").fillna(0.0)
    for f in ("Data de Inicio", "Data de Cobrança"):
        p[f] = pd.to_datetime(p.get(f), errors="coerce")
    for f in ("Estado", "Estado do Contrato", "M. Pagamento", "Prazo/Duração", "Nome do Cliente"):
        p[f] = p.get(f, pd.Series(index=p.index, dtype="object")).fillna("Não indicado").astype(str).str.strip().replace("", "Não indicado")
    faixas = pd.cut(p["Quantia Creditada"], [-float("inf"), 5000, 25000, 100000, float("inf")], labels=FAIXAS).astype("string")
    if "Intervalo" in p:
        p["Intervalo"] = p["Intervalo"].astype("string").str.strip().replace("", pd.NA).fillna(faixas)
    else:
        p["Intervalo"] = faixas
    cid = p["Nº de Cliente"] if "Nº de Cliente" in p else pd.Series(pd.NA, index=p.index)
    p["cliente"] = cid.astype("string").fillna("n:" + p["Nome do Cliente"])
    p["pago"] = p["Estado"].str.startswith("Pago")
    black = {str(x) for x in data.get("lista_negra", [])}
    p["lista_negra"] = p["cliente"].isin(black)
    today = pd.Timestamp(datetime.now(TZ).date())
    p["vencida"] = (~p["pago"]) & (p["Data de Cobrança"] < today)
    p["com_atraso"] = p["Estado"].isin(["Pago em Atraso", "Pagamento Atrasado"]) | p["vencida"]
    p["capital"] = (p["Valor a Pagar"] - p["Valor de Juros"]).clip(lower=0)

    c = (p.sort_values("Data de Inicio").groupby("Nr do Contracto", as_index=False)
         .agg(cliente=("cliente", "first"), nome=("Nome do Cliente", "first"), inicio=("Data de Inicio", "min"),
              quantia=("Quantia Creditada", "first"), juros=("Valor de Juros", "sum"), prazo=("Prazo/Duração", "first"),
              modalidade=("M. Pagamento", "first"), faixa=("Intervalo", "first"), estado=("Estado do Contrato", "first"),
              prestacoes=("Estado", "size")))
    first = c.groupby("cliente")["inicio"].transform("min")
    c["primeiro"] = c["inicio"].eq(first)

    cl = pd.DataFrame.from_records(data.get("clientes", []))
    if cl.empty:
        cl = pd.DataFrame(columns=["Nome Completo", "Estado Civil"])
    return p, c, cl


# ---------------------------------------------------------------------------
# Período e agrupamento
# ---------------------------------------------------------------------------
class Period:
    def __init__(self, ano, mes, dmin: pd.Timestamp, dmax: pd.Timestamp):
        self.todos = ano == "Todos"
        dmin, dmax = pd.Timestamp(dmin), pd.Timestamp(dmax)
        if self.todos:
            self.start, self.end = dmin.normalize(), (dmax + pd.DateOffset(days=1)).normalize()
            self.prev = None
            self.label, self.prev_label, self.gran = "Todo o histórico", "", "M"
        elif mes == "Todos":
            y = int(ano)
            self.start, self.end = pd.Timestamp(y, 1, 1), pd.Timestamp(y + 1, 1, 1)
            self.prev = (pd.Timestamp(y - 1, 1, 1), self.start)
            self.label, self.prev_label, self.gran = f"Ano de {y}", f"vs {y - 1}", "M"
        else:
            y, m = int(ano), int(mes)
            self.start = pd.Timestamp(y, m, 1)
            self.end = self.start + pd.offsets.MonthBegin(1)
            ps = self.start - pd.offsets.MonthBegin(1)
            self.prev = (ps, self.start)
            self.label = f"{MESES_LONGOS[m - 1]} de {y}"
            self.prev_label, self.gran = f"vs {MESES[ps.month - 1]} {ps.year}", "D"

    def mask(self, s: pd.Series) -> pd.Series:
        return (s >= self.start) & (s < self.end)

    def pmask(self, s: pd.Series) -> pd.Series:
        return pd.Series(False, index=s.index) if self.prev is None else (s >= self.prev[0]) & (s < self.prev[1])


def bucket(s: pd.Series, gran: str) -> pd.Series:
    return s.dt.normalize() if gran == "D" else s.dt.to_period("M").dt.to_timestamp()


def blabel(ts: pd.Timestamp, gran: str, multi_year: bool) -> str:
    if gran == "D":
        return f"{ts.day:02d} {MESES[ts.month - 1]}"
    return f"{MESES[ts.month - 1]} {ts.year}" if multi_year else MESES[ts.month - 1]


def series(df: pd.DataFrame, date_col: str, value_col: str | None, per: Period, agg: str = "sum") -> pd.DataFrame:
    if df.empty:
        return pd.DataFrame(columns=["t", "v", "rotulo"])
    b = bucket(df[date_col], per.gran)
    g = (df.assign(t=b).groupby("t")[value_col].agg(agg) if value_col else df.assign(t=b).groupby("t").size())
    g = g.rename("v").reset_index().sort_values("t")
    multi = g["t"].dt.year.nunique() > 1
    g["rotulo"] = [blabel(t, per.gran, multi) for t in g["t"]]
    return g


# ---------------------------------------------------------------------------
# Painéis
# ---------------------------------------------------------------------------
def page_carteira(p: pd.DataFrame, c: pd.DataFrame, per: Period) -> None:
    cp, cprev = c[per.mask(c["inicio"])], c[per.pmask(c["inicio"])]
    due, dprev = p[per.mask(p["Data de Cobrança"])], p[per.pmask(p["Data de Cobrança"])]
    inad = ratio(due["com_atraso"].sum(), len(due))
    inad_prev = ratio(dprev["com_atraso"].sum(), len(dprev)) if per.prev else None
    pv = per.prev is not None

    section("Desembolsos", f"{per.label} · contratos iniciados no período")
    kpi_row([
        kpi("Contratos desembolsados", num(len(cp)), "contratos", delta(len(cp), len(cprev)) if pv else None, per.prev_label, "hl"),
        kpi("Valor desembolsado", mzn_short(cp["quantia"].sum()), "dinheiro", delta(cp["quantia"].sum(), cprev["quantia"].sum()) if pv else None, mzn(cp["quantia"].sum())),
        kpi("Empréstimo médio", mzn_short(cp["quantia"].mean() if len(cp) else 0), "media",
            delta(cp["quantia"].mean(), cprev["quantia"].mean()) if pv and len(cp) and len(cprev) else None),
        kpi("Juros contratados", mzn_short(cp["juros"].sum()), "juros", delta(cp["juros"].sum(), cprev["juros"].sum()) if pv else None,
            "Juros previstos nos contratos iniciados"),
        kpi("Taxa de inadimplência", pct(inad), "alerta", delta(inad, inad_prev, good_up=False, points=True) if pv else None,
            f"{num(due['com_atraso'].sum())} de {num(len(due))} prestações com vencimento no período", "alert" if (inad or 0) >= 20 else ""),
    ])

    # Carteira corrente: sem o crédito dos mutuários na lista negra (mostrado à parte).
    live_all = p[~p["pago"]]
    black = live_all[live_all["lista_negra"]]
    live = live_all[~live_all["lista_negra"]]
    overdue = live[live["vencida"]]
    em_curso = c[c["estado"].eq("Em Progresso") & ~c["Nr do Contracto"].isin(black["Nr do Contracto"])]
    par = ratio(overdue["Valor a Pagar"].sum(), live["Valor a Pagar"].sum())
    section("Carteira em curso", "Situação actual (hoje), com os filtros aplicados")
    kpi_row([
        kpi("Contratos em curso", num(len(em_curso)), "contratos", note=f"{num(live['Nr do Contracto'].nunique())} com prestações por pagar"),
        kpi("Prestações por pagar", num(len(live)), "prestacoes", note="Pendentes e em atraso"),
        kpi("Pagamento esperado", mzn_short(live["Valor a Pagar"].sum()), "dinheiro", note=mzn(live["Valor a Pagar"].sum())),
        kpi("Em atraso", mzn_short(overdue["Valor a Pagar"].sum()), "alerta", note=f"{num(len(overdue))} prestações vencidas · {num(overdue['Nr do Contracto'].nunique())} contratos",
            cls="alert" if len(overdue) else ""),
        kpi("Carteira em risco (PAR)", pct(par), "risco", note="Valor vencido ÷ valor por receber", cls="alert" if (par or 0) >= 10 else ""),
    ] + ([kpi("Crédito em lista negra", mzn_short(black["Valor a Pagar"].sum()), "alerta",
              note=f"{num(black['cliente'].nunique())} mutuário(s) · {num(black['Nr do Contracto'].nunique())} contrato(s) · fora da carteira corrente", cls="alert")]
         if len(black) else []))

    section("Evolução e composição", per.label)
    if cp.empty:
        empty("Sem contratos iniciados no período seleccionado.")
    else:
        a, b = st.columns(2, gap="medium")
        with a:
            s = series(cp, "inicio", "quantia", per)
            n = series(cp, "inicio", None, per)
            fig = go.Figure(go.Bar(x=s["rotulo"], y=s["v"], marker_color=T["primary"], customdata=n["v"],
                                   hovertemplate="%{x}<br><b>%{y:,.2f} MT</b><br>%{customdata} contrato(s)<extra></extra>"))
            chart(style(fig, "Valor desembolsado"), "desembolsos")
        with b:
            s = series(cp, "inicio", "juros", per)
            fig = go.Figure(go.Bar(x=s["rotulo"], y=s["v"], marker_color=T["accent"],
                                   hovertemplate="%{x}<br><b>%{y:,.2f} MT</b><extra></extra>"))
            chart(style(fig, "Juros contratados"), "juros")
        a, b = st.columns(2, gap="medium")
        fx = (cp.groupby("faixa").agg(valor=("quantia", "sum"), n=("quantia", "size"))
              .reindex([f for f in FAIXAS if f in set(cp["faixa"])] + sorted(set(cp["faixa"]) - set(FAIXAS))).dropna().reset_index())
        with a:
            fig = go.Figure(go.Pie(labels=fx["faixa"], values=fx["valor"], hole=0.62, sort=False, marker=dict(colors=PALETTE),
                                   textinfo="percent", hovertemplate="%{label}<br><b>%{value:,.2f} MT</b> (%{percent})<extra></extra>"))
            fig.add_annotation(text=f"<b>{mzn_short(cp['quantia'].sum())}</b><br><span style='font-size:11px'>desembolsado</span>",
                               showarrow=False, font=dict(family="Manrope", size=15, color=T["heading"]))
            chart(style(fig, "Valor por faixa de crédito", legend=True), "faixa_valor")
        with b:
            fig = go.Figure(go.Bar(x=fx["n"], y=fx["faixa"], orientation="h", marker_color=PALETTE[: len(fx)],
                                   hovertemplate="%{y}<br><b>%{x} contrato(s)</b><extra></extra>"))
            fig.update_yaxes(autorange="reversed")
            chart(style(fig, "Contratos por faixa de crédito", kind="n"), "faixa_n")


def retention_series(c: pd.DataFrame) -> pd.DataFrame:
    """Retenção e aquisição mês a mês (meses consecutivos com contratos)."""
    m = c.assign(t=c["inicio"].dt.to_period("M")).dropna(subset=["t"])
    months = sorted(m["t"].unique())
    rows, seen = [], set()
    prev = None
    for t in months:
        cur = set(m.loc[m["t"] == t, "cliente"])
        new = cur - seen
        rows.append(dict(t=t.to_timestamp(), clientes=len(cur), novos=len(new), recorrentes=len(cur) - len(new),
                         retencao=ratio(len(cur & prev), len(prev)) if prev else None, aquisicao=ratio(len(new), len(cur))))
        seen |= cur
        prev = cur
    return pd.DataFrame(rows)


def page_mutuarios(p: pd.DataFrame, c: pd.DataFrame, cl: pd.DataFrame, per: Period) -> None:
    cp, cprev = c[per.mask(c["inicio"])], c[per.pmask(c["inicio"])]
    cur, prev = set(cp["cliente"]), set(cprev["cliente"])
    novos = set(cp.loc[cp["primeiro"], "cliente"])
    novos_prev = set(cprev.loc[cprev["primeiro"], "cliente"])
    rs = retention_series(c)
    if per.prev is None:
        ret = rs["retencao"].dropna().mean() if not rs.empty else None
        aq = rs["aquisicao"].dropna().mean() if not rs.empty else None
        ret_note, aq_note = "Média mensal", "Média mensal"
        d_ret = d_aq = None
    else:
        ret = ratio(len(cur & prev), len(prev))
        aq = ratio(len(novos), len(cur))
        ret_note = (f"{num(len(cur & prev))} de {num(len(prev))} voltaram a pedir crédito" if prev
                    else "Sem mutuários no período anterior")
        aq_note = f"{num(len(novos))} de {num(len(cur))} mutuários"
        pp = per.prev
        pc2 = c[(c["inicio"] >= pp[0] - (pp[1] - pp[0])) & (c["inicio"] < pp[0])]
        d_ret = delta(ret, ratio(len(prev & set(pc2["cliente"])), pc2["cliente"].nunique()), points=True)
        d_aq = delta(aq, ratio(len(novos_prev), len(prev)), points=True)
    pv = per.prev is not None

    section("Mutuários", f"{per.label} · com contratos iniciados no período")
    kpi_row([
        kpi("Mutuários com crédito", num(len(cur)), "pessoas", delta(len(cur), len(prev)) if pv else None, per.prev_label, "hl"),
        kpi("Novos mutuários", num(len(novos)), "novo", delta(len(novos), len(novos_prev)) if pv else None, "Primeiro contrato no período"),
        kpi("Recorrentes", num(len(cur - novos)), "repete", note="Já tinham crédito antes"),
        kpi("Taxa de retenção", pct(ret), "repete", d_ret, ret_note),
        kpi("Taxa de aquisição", pct(aq), "cresce", d_aq, aq_note),
    ])

    if cp.empty:
        empty("Sem contratos iniciados no período seleccionado.")
        return
    section("Comportamento dos mutuários", per.label)
    a, b = st.columns([1.25, 1], gap="medium")
    with a:
        nv = cp.assign(tipo=cp["primeiro"].map({True: "Novos", False: "Recorrentes"}))
        g = nv.assign(t=bucket(nv["inicio"], per.gran)).groupby(["t", "tipo"])["cliente"].nunique().reset_index()
        multi = g["t"].dt.year.nunique() > 1
        g["rotulo"] = [blabel(t, per.gran, multi) for t in g["t"]]
        fig = px.bar(g.sort_values("t"), x="rotulo", y="cliente", color="tipo", barmode="stack",
                     color_discrete_map={"Novos": T["accent"], "Recorrentes": T["primary"]},
                     category_orders={"tipo": ["Recorrentes", "Novos"]})
        fig.update_traces(hovertemplate="%{x}<br><b>%{y}</b> mutuário(s)<extra>%{fullData.name}</extra>")
        fig.update_layout(legend_title_text="")
        chart(style(fig, "Novos e recorrentes", legend=True, kind="n"), "novos")
    with b:
        merged = (cp.drop_duplicates("cliente")[["nome"]]
                  .merge(cl.drop_duplicates("Nome Completo"), left_on="nome", right_on="Nome Completo", how="left"))
        ec = merged["Estado Civil"].fillna("Não indicado").replace("", "Não indicado").value_counts()
        fig = go.Figure(go.Pie(labels=ec.index, values=ec.values, hole=0.62, marker=dict(colors=PALETTE),
                               hovertemplate="%{label}<br><b>%{value}</b> (%{percent})<extra></extra>"))
        chart(style(fig, "Estado civil", legend=True), "civil")

    a, b = st.columns(2, gap="medium")
    top = cp.groupby("nome").agg(valor=("quantia", "sum"), n=("quantia", "size")).reset_index()
    with a:
        t = top.nlargest(10, "valor").sort_values("valor")
        fig = go.Figure(go.Bar(x=t["valor"], y=t["nome"], orientation="h", marker_color=T["primary"], customdata=t["n"],
                               hovertemplate="%{y}<br><b>%{x:,.2f} MT</b> · %{customdata} contrato(s)<extra></extra>"))
        chart(style(fig, "Top 10 mutuários por crédito", height=380), "top_valor")
    with b:
        t = top.nlargest(10, "n").sort_values("n")
        fig = go.Figure(go.Bar(x=t["n"], y=t["nome"], orientation="h", marker_color=T["accent"],
                               hovertemplate="%{y}<br><b>%{x} contrato(s)</b><extra></extra>"))
        chart(style(fig, "Top 10 mutuários por nº de contratos", height=380, kind="n"), "top_n")


def growth(c: pd.DataFrame, per: Period) -> tuple[float | None, pd.DataFrame]:
    m = c.dropna(subset=["inicio"]).assign(t=c["inicio"].dt.to_period("M").dt.to_timestamp()).groupby("t")["quantia"].sum()
    if not m.empty:
        m = m.reindex(pd.date_range(m.index.min(), m.index.max(), freq="MS"), fill_value=0)
    g = (m.pct_change() * 100).replace([float("inf"), -float("inf")], pd.NA)
    df = pd.DataFrame({"t": m.index, "v": g.values})
    if per.prev is None:
        return (g.dropna().mean() if g.notna().any() else None), df
    cur = c.loc[per.mask(c["inicio"]), "quantia"].sum()
    prev = c.loc[per.pmask(c["inicio"]), "quantia"].sum()
    return ratio(cur - prev, prev), df[(df["t"] >= per.start - pd.offsets.MonthBegin(1)) & (df["t"] < per.end)]


def page_financeiro(p: pd.DataFrame, c: pd.DataFrame, per: Period) -> None:
    due, dprev = p[per.mask(p["Data de Cobrança"])], p[per.pmask(p["Data de Cobrança"])]
    paid, pend = due[due["pago"]], due[~due["pago"]]
    ppaid = dprev[dprev["pago"]]
    tj = ratio(due["Valor de Juros"].sum(), due["capital"].sum())
    tj_prev = ratio(dprev["Valor de Juros"].sum(), dprev["capital"].sum()) if per.prev else None
    tc, gdf = growth(c, per)
    pv = per.prev is not None

    section("Prestações do período", f"{per.label} · por data de vencimento")
    kpi_row([
        kpi("Prestações", num(len(due)), "prestacoes", delta(len(due), len(dprev)) if pv else None,
            f"{num(len(paid))} pagas · {num(len(pend))} por pagar", "hl"),
        kpi("Valor recebido", mzn_short(paid["Valor Pago"].sum()), "recebido", delta(paid["Valor Pago"].sum(), ppaid["Valor Pago"].sum()) if pv else None,
            mzn(paid["Valor Pago"].sum())),
        kpi("Por receber", mzn_short(pend["Valor a Pagar"].sum()), "relogio", note=f"{num(len(pend))} prestações", cls="alert" if pend["vencida"].any() else ""),
        kpi("Juros do período", mzn_short(due["Valor de Juros"].sum()), "juros", delta(due["Valor de Juros"].sum(), dprev["Valor de Juros"].sum()) if pv else None,
            f"Realizados: {mzn(paid['Valor de Juros'].sum())}"),
        kpi("Taxa de juro efectiva", pct(tj, 2), "juros", delta(tj, tj_prev, points=True) if pv else None, "Juros ÷ capital das prestações"),
        kpi("Crescimento dos desembolsos", pct(tc), "cresce", note="Média mensal" if per.prev is None else per.prev_label.replace("vs", "Face a")),
    ])

    back = p[(~p["pago"]) & (p["Data de Cobrança"] < per.start)] if not per.todos else p.iloc[0:0]
    if len(back):
        section("Atrasos acumulados", f"Prestações vencidas antes de {per.label.lower()} ainda por pagar")
        kpi_row([
            kpi("Prestações em atraso", num(len(back)), "alerta", note=f"{num(back['Nr do Contracto'].nunique())} contratos", cls="alert"),
            kpi("Valor por pagar", mzn_short(back["Valor a Pagar"].sum()), "dinheiro", note=mzn(back["Valor a Pagar"].sum())),
            kpi("Capital", mzn_short(back["capital"].sum()), "contratos"),
            kpi("Juros", mzn_short(back["Valor de Juros"].sum()), "juros"),
        ])

    section("Evolução financeira", per.label)
    if due.empty and gdf.empty:
        empty("Sem prestações com vencimento no período seleccionado.")
        return
    a, b = st.columns(2, gap="medium")
    with a:
        s = series(due, "Data de Cobrança", "Valor de Juros", per)
        fig = go.Figure(go.Scatter(x=s["rotulo"], y=s["v"], mode="lines+markers", line=dict(color=T["primary"], width=3),
                                   fill="tozeroy", fillcolor=rgba(T["primary"], .09), marker=dict(size=7),
                                   hovertemplate="%{x}<br><b>%{y:,.2f} MT</b><extra></extra>"))
        chart(style(fig, "Juros (lucro) por vencimento"), "lucro")
    with b:
        if gdf.empty or gdf["v"].isna().all():
            empty("Sem meses suficientes para calcular o crescimento.")
        else:
            gg = gdf.dropna()
            multi = gg["t"].dt.year.nunique() > 1
            fig = go.Figure(go.Bar(x=[blabel(t, "M", multi) for t in gg["t"]], y=gg["v"],
                                   marker_color=[T["accent"] if v >= 0 else T["danger"] for v in gg["v"]],
                                   hovertemplate="%{x}<br><b>%{y:,.1f} %</b><extra></extra>"))
            fig = style(fig, "Crescimento mensal dos desembolsos", kind="pct")
            fig.update_yaxes(zeroline=True, zerolinecolor=T["border"])
            chart(fig, "crescimento")
    if not due.empty:
        r = series(paid, "Data de Cobrança", "Valor Pago", per).rename(columns={"v": "Recebido"})
        q = series(pend, "Data de Cobrança", "Valor a Pagar", per).rename(columns={"v": "Por receber"})
        rq = r.merge(q, on=["t", "rotulo"], how="outer").fillna(0).sort_values("t")
        fig = go.Figure([go.Bar(name="Recebido", x=rq["rotulo"], y=rq["Recebido"], marker_color=T["accent"]),
                         go.Bar(name="Por receber", x=rq["rotulo"], y=rq["Por receber"], marker_color="#C9BFE3")])
        fig.update_layout(barmode="stack")
        fig.update_traces(hovertemplate="%{x}<br><b>%{y:,.2f} MT</b><extra>%{fullData.name}</extra>")
        chart(style(fig, "Recebido e por receber (por vencimento)", legend=True), "recebimentos")


# ---------------------------------------------------------------------------
# Página
# ---------------------------------------------------------------------------
css()
if "nonce" not in st.session_state:
    st.session_state.nonce = 0
try:
    cookie = st.context.cookies.get("cis_streamlit", "")
    raw = fetch(cookie if isinstance(cookie, str) else "", st.session_state.nonce)
except (requests.RequestException, ValueError):
    st.error("Não foi possível consultar o Dashboard. Volte a abrir o separador no CIS.", icon=":material/error:")
    st.stop()

p_all, c_all, clientes = prepare(raw)
if p_all.empty:
    empty("Não há contratos disponíveis para a sua agência.")
    st.stop()

now = datetime.now(TZ)
PAGES = {"carteira": ":material/account_balance_wallet: Carteira", "mutuarios": ":material/groups: Mutuários",
         "financeiro": ":material/trending_up: Financeiro"}

with st.container(key="bar_top", horizontal=True, vertical_alignment="bottom", gap="medium"):
    page = st.segmented_control("Painel", list(PAGES), format_func=PAGES.get, default="carteira", required=True,
                                key="pagina", label_visibility="collapsed")
    anos = sorted(c_all["inicio"].dropna().dt.year.unique().tolist(), reverse=True)
    ano_opts = ["Todos"] + [str(a) for a in anos]
    ano = st.selectbox("Ano", ano_opts, index=ano_opts.index(str(now.year)) if str(now.year) in ano_opts else 0, width=130)
    mes_opts = ["Todos"] + list(range(1, 13))
    mes = st.selectbox("Mês", mes_opts, index=0 if ano == "Todos" else now.month if ano == str(now.year) else 0,
                       format_func=lambda m: "Todos" if m == "Todos" else MESES_LONGOS[m - 1], disabled=ano == "Todos", width=150)
    if ano == "Todos":
        mes = "Todos"
    n_act = sum(bool(st.session_state.get(k)) for k in ("f_prazo", "f_mod", "f_faixa", "f_cli"))
    with st.popover("Filtros" + (f" · {n_act}" if n_act else ""), icon=":material/tune:"):
        st.multiselect("Prazo", sorted(p_all["Prazo/Duração"].unique()), key="f_prazo", placeholder="Todos os prazos")
        st.multiselect("Modalidade de pagamento", sorted(p_all["M. Pagamento"].unique()), key="f_mod", placeholder="Todas")
        st.multiselect("Faixa de crédito", [f for f in FAIXAS if f in set(p_all["Intervalo"])], key="f_faixa", placeholder="Todas as faixas")
        st.multiselect("Mutuário", sorted(p_all["Nome do Cliente"].unique()), key="f_cli", placeholder="Todos os mutuários")
    if st.button("Actualizar", icon=":material/refresh:", help="Volta a ler os dados do CIS"):
        st.session_state.nonce += 1
        st.rerun()

p = p_all
if st.session_state.get("f_prazo"):
    p = p[p["Prazo/Duração"].isin(st.session_state.f_prazo)]
if st.session_state.get("f_mod"):
    p = p[p["M. Pagamento"].isin(st.session_state.f_mod)]
if st.session_state.get("f_faixa"):
    p = p[p["Intervalo"].isin(st.session_state.f_faixa)]
if st.session_state.get("f_cli"):
    p = p[p["Nome do Cliente"].isin(st.session_state.f_cli)]
c = c_all[c_all["Nr do Contracto"].isin(p["Nr do Contracto"].unique())]

dates = pd.concat([p["Data de Inicio"], p["Data de Cobrança"]]).dropna()
per = Period(ano, mes, dates.min() if len(dates) else pd.Timestamp(now.date()), dates.max() if len(dates) else pd.Timestamp(now.date()))

titles = {"carteira": ("Desempenho da carteira", "Desembolsos, carteira em curso, inadimplência e faixas de crédito."),
          "mutuarios": ("Mutuários", "Clientes com crédito, novos clientes, retenção e aquisição."),
          "financeiro": ("Desempenho financeiro e crescimento", "Recebimentos, juros, taxa efectiva e crescimento dos desembolsos.")}
t, sub = titles[page]
st.html(f'<div class="cis-head"><h2>{html.escape(t)}</h2><span class="cis-kicker">{html.escape(per.label)}</span>'
        f'<p>{html.escape(sub)}</p></div>')

if p.empty:
    empty("Sem contratos para os filtros seleccionados.")
elif page == "carteira":
    page_carteira(p, c, per)
elif page == "mutuarios":
    page_mutuarios(p, c, clientes, per)
else:
    page_financeiro(p, c, per)

with st.expander("Como são calculados os indicadores", icon=":material/info:"):
    st.markdown("""
- **Desembolsos**: contratos com *Data de Início* no período; o valor é a *Quantia Creditada* de cada contrato (contada uma vez).
- **Taxa de inadimplência**: prestações com vencimento no período pagas em atraso ou ainda em atraso ÷ prestações com vencimento no período.
- **Carteira em curso / PAR**: prestações por pagar hoje; *em atraso* = por pagar com vencimento antes de hoje. PAR = valor vencido ÷ valor por receber.
- **Novos mutuários**: o primeiro contrato do mutuário é deste período. **Retenção**: mutuários do período anterior que voltaram a pedir crédito. **Aquisição**: novos ÷ mutuários do período. Em «Todos», média mensal.
- **Taxa de juro efectiva**: juros ÷ capital (valor a pagar − juros) das prestações com vencimento no período.
- **Crescimento**: variação do valor desembolsado face ao período anterior (em «Todos», média das variações mensais).
- As variações (▲ ▼) comparam com o período anterior equivalente (mês ou ano). Verde = melhor, vermelho = pior.
""")
st.html(f'<div class="cis-foot">Fonte: CIS · contratos da sua agência e carteira · {num(c["Nr do Contracto"].nunique())} contratos, '
        f'{num(len(p))} prestações · lido às {now:%H:%M} (actualiza a cada 5 minutos)</div>')
