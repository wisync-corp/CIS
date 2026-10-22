"""CIS Capta — dashboard Streamlit (consulta, sem escrita)."""
from __future__ import annotations

import os
from datetime import date

import pandas as pd
import plotly.express as px
import requests
import streamlit as st
from sqlalchemy import create_engine, text
from sqlalchemy.engine import URL
from schema_validation import validate_schema

st.set_page_config(page_title="CIS | Capta Microcrédito", page_icon="📊", layout="wide",
                   initial_sidebar_state="expanded")

st.markdown("""
<style>
[data-testid="stAppViewContainer"] {background: #f6f9f7}
[data-testid="stSidebar"] {background: #103b31}
[data-testid="stSidebar"] * {color:#eef8f1 !important}
[data-testid="stMetric"] {background:#fff;border:1px solid #e0eae2;padding:18px;border-radius:14px}
h1,h2,h3 {color:#214736 !important}
.stButton>button[kind="primary"] {background:#24654d;border-color:#24654d}
.cis-title {padding:14px 0 22px;border-bottom:1px solid #dbe9df;margin-bottom:20px}
.cis-subtitle {color:#769184;font-size:14px}
.cis-note {border:1px solid #cfe5d5;background:#ebf6ef;border-radius:10px;padding:14px;color:#345f45}
</style>
""", unsafe_allow_html=True)

SECTIONS = ("Desempenho do Portfólio de Microcrédito", "Análise de Clientes",
            "Desempenho Financeiro e Crescimento")
ALLOWED_ROLES = {"administrador", "gestor"}
CURRENCY = "MZN"
API_URL = os.getenv("CIS_API_URL", "http://127.0.0.1:8000/api").rstrip("/")


def money(value: float) -> str:
    return f"{CURRENCY} {value:,.2f}".replace(",", " ").replace(".", ",") if pd.notna(value) else "—"


def api_request(method: str, path: str, **kwargs):
    response = requests.request(method, API_URL + path, timeout=12, **kwargs)
    response.raise_for_status()
    return response.json() if response.content else None


def login_screen() -> None:
    st.markdown("## CAPTA · MICROCRÉDITO")
    st.title("Aceder ao dashboard CIS")
    st.caption("Acesso reservado ao administrador e gestor. Autenticação via API CIS.")
    with st.form("login", clear_on_submit=False):
        email = st.text_input("Email", autocomplete="username")
        password = st.text_input("Palavra-passe", type="password", autocomplete="current-password")
        submitted = st.form_submit_button("Entrar", type="primary")
    if submitted:
        try:
            result = api_request("POST", "/auth/login", json={"email": email, "password": password})
            user = result["user"]
            if user.get("role") not in ALLOWED_ROLES:
                # Revogar imediatamente o token sem o guardar em sessão.
                try:
                    api_request("POST", "/auth/logout", headers={"Authorization": "Bearer " + result["token"]})
                except requests.RequestException:
                    pass
                st.error("O seu perfil não tem autorização para o dashboard.")
                return
            st.session_state["cis_token"] = result["token"]
            st.session_state["cis_user"] = user
            st.rerun()
        except (requests.RequestException, KeyError, TypeError):
            st.error("Não foi possível iniciar sessão. Verifique as credenciais e a API.")


def session_valid() -> bool:
    token = st.session_state.get("cis_token")
    if not token:
        return False
    try:
        profile = api_request("GET", "/auth/me", headers={"Authorization": "Bearer " + token})
        if profile.get("role") not in ALLOWED_ROLES:
            st.session_state.pop("cis_token", None)
            st.session_state.pop("cis_user", None)
            return False
        st.session_state["cis_user"] = profile
        return True
    except (requests.RequestException, TypeError, AttributeError):
        st.session_state.pop("cis_token", None)
        st.session_state.pop("cis_user", None)
        return False


def logout() -> None:
    token = st.session_state.pop("cis_token", None)
    st.session_state.pop("cis_user", None)
    if token:
        try:
            api_request("POST", "/auth/logout", headers={"Authorization": "Bearer " + token})
        except requests.RequestException:
            pass
    st.rerun()


@st.cache_resource(show_spinner=False)
def db_engine():
    required = ("LEGACY_DB_HOST", "LEGACY_DB_DATABASE", "LEGACY_DB_USERNAME")
    missing = [k for k in required if not os.getenv(k)]
    if missing:
        raise RuntimeError("Configuração MySQL incompleta: " + ", ".join(missing))
    options = {}
    ca = os.getenv("LEGACY_DB_SSL_CA", "").strip()
    if ca:
        options["ssl"] = {"ca": ca}
    url = URL.create(
        "mysql+pymysql", username=os.environ["LEGACY_DB_USERNAME"],
        password=os.getenv("LEGACY_DB_PASSWORD", ""),
        host=os.environ["LEGACY_DB_HOST"], port=int(os.getenv("LEGACY_DB_PORT", "3306")),
        database=os.environ["LEGACY_DB_DATABASE"],
    )
    return create_engine(url, connect_args=options, pool_pre_ping=True, pool_recycle=300)


@st.cache_data(ttl=90, show_spinner=False)
def load_data() -> tuple[pd.DataFrame, pd.DataFrame]:
    # Conta SQL deve estar limitada a SELECT por configuração do servidor MySQL.
    validation = validate_schema(db_engine())
    if not validation.ok:
        raise RuntimeError("SQL_SCHEMA_VALIDATION_FAILED: " + " | ".join(validation.details))
    with db_engine().connect() as connection:
        # Colunas explicitamente seleccionadas para limitar informação pessoal exposta.
        contracts = pd.read_sql(text("""
            SELECT `Nr do Contracto`, `Nº da Parcela`, `Nº de Prestações`,
                   `Nº de Cliente`, `Nome do Cliente`, `Data de Inicio`,
                   `Data de Cobrança`, `Prazo/Duração`, `M. Pagamento`,
                   `Intervalo`, `Quantia Creditada`, `Capital`,
                   `Amortização`, `Valor de Juros`, `Valor de Multa`,
                   `Valor a Pagar`, `Valor Pago`, `Estado`, `Estado do Contrato`
            FROM Contractos
        """), connection)
        clients = pd.read_sql(text("""
            SELECT `ID`, `Nome Completo`, `Estado Civil` FROM Clientes
        """), connection)
    return contracts, clients


def clean_contracts(frame: pd.DataFrame) -> pd.DataFrame:
    frame = frame.copy()
    for column in ("Data de Inicio", "Data de Cobrança"):
        frame[column] = pd.to_datetime(frame[column], errors="coerce")
    for column in ("Quantia Creditada", "Capital", "Amortização", "Valor de Juros",
                   "Valor de Multa", "Valor a Pagar", "Valor Pago"):
        frame[column] = pd.to_numeric(frame[column], errors="coerce").fillna(0)
    for column in ("Estado", "Estado do Contrato"):
        frame[column] = frame[column].fillna("").astype(str).str.strip()
    return frame


def sidebar_filters(frame: pd.DataFrame) -> pd.DataFrame:
    with st.sidebar:
        st.markdown("## CAPTA")
        st.caption("CIS · Dashboard de gestão")
        section = st.selectbox("Painel", SECTIONS, key="cis_section")
        st.divider()
        years = sorted(frame["Data de Inicio"].dt.year.dropna().astype(int).unique().tolist())
        year = st.selectbox("Ano", ["Todos"] + years)
        months = list(range(1, 13))
        month = st.selectbox("Mês", ["Todos"] + months, format_func=lambda m: "Todos" if m == "Todos" else
                             date(2000, int(m), 1).strftime("%m"))
        result = frame.copy()
        if year != "Todos":
            result = result[result["Data de Inicio"].dt.year == year]
        if month != "Todos":
            result = result[result["Data de Inicio"].dt.month == month]
        for label, col in (("Prazo de pagamento", "Prazo/Duração"),
                           ("Modalidade de pagamento", "M. Pagamento"),
                           ("Cliente", "Nome do Cliente")):
            opts = sorted(frame[col].dropna().astype(str).unique().tolist())
            selected = st.multiselect(label, options=opts, placeholder="Todos")
            if selected:
                result = result[result[col].astype(str).isin(selected)]
        st.divider()
        st.caption("Consulta de leitura · dados do ambiente configurado")
        if st.button("Actualizar dados"):
            load_data.clear()
            st.rerun()
        if st.button("Terminar sessão"):
            logout()
    return section, result


def unique_loans(frame: pd.DataFrame) -> pd.DataFrame:
    # A tabela Contractos possui 1 linha por prestação; não somar o crédito em todas as linhas.
    return frame.sort_values("Data de Inicio").drop_duplicates(subset=["Nr do Contracto"])


def plot_bar(data: pd.DataFrame, x: str, y: str, title: str, horizontal=False) -> None:
    if data.empty:
        st.info("Sem dados para este gráfico.")
        return
    fig = px.bar(data, x=y if horizontal else x, y=x if horizontal else y,
                 orientation="h" if horizontal else "v", title=title)
    fig.update_layout(template="plotly_white", margin=dict(l=15, r=10, t=50, b=25),
                      colorway=["#27684d"], showlegend=False)
    st.plotly_chart(fig, use_container_width=True)


def portfolio(frame: pd.DataFrame) -> None:
    st.title("Desempenho do Portfólio de Microcrédito")
    st.caption("Volume de contratos e prestações. Capital concedido contado uma vez por empréstimo.")
    loans = unique_loans(frame)
    pending = frame[frame["Estado"].str.casefold().isin(["pendente", "pagamento atrasado"])]
    one, two, three, four = st.columns(4)
    one.metric("Empréstimos distintos", len(loans))
    two.metric("Prestações registadas", len(frame))
    three.metric("Quantia creditada", money(loans["Quantia Creditada"].sum()))
    four.metric("Prestações pendentes", len(pending))
    st.markdown("#### Distribuição da carteira")
    a,b = st.columns(2)
    with a:
        monthly = loans.dropna(subset=["Data de Inicio"]).assign(
            Periodo=lambda x:x["Data de Inicio"].dt.to_period("M").astype(str))
        chart = monthly.groupby("Periodo", as_index=False)["Quantia Creditada"].sum().sort_values("Periodo")
        plot_bar(chart, "Periodo", "Quantia Creditada", "Crédito concedido por mês (MZN)")
    with b:
        chart = loans["Intervalo"].fillna("Não definido").value_counts().rename_axis("Intervalo").reset_index(name="Empréstimos")
        plot_bar(chart, "Intervalo", "Empréstimos", "Empréstimos por faixa", horizontal=True)
    st.markdown('<div class="cis-note">As prestações pendentes são contadas por linha. Montantes exigíveis e inadimplência financeira necessitam de reconciliação de pagamentos parciais e renovações.</div>', unsafe_allow_html=True)


def clients_dashboard(frame: pd.DataFrame, clients: pd.DataFrame) -> None:
    st.title("Análise de Clientes")
    st.caption("Clientes com empréstimos no período seleccionado; identificação por Nº de Cliente.")
    loans = unique_loans(frame)
    count = loans["Nº de Cliente"].dropna().nunique()
    a,b,c = st.columns(3)
    a.metric("Clientes com contratos", count)
    b.metric("Empréstimos distintos", len(loans))
    c.metric("Empréstimos por cliente", f"{len(loans)/count:,.2f}" if count else "—")
    one,two = st.columns(2)
    with one:
        per_client = loans.groupby("Nome do Cliente",as_index=False).agg(Empréstimos=("Nr do Contracto","nunique"))
        plot_bar(per_client.nlargest(15,"Empréstimos"),"Nome do Cliente","Empréstimos",
                 "Clientes por quantidade de empréstimos (máx. 15)",horizontal=True)
    with two:
        st.subheader("Estado civil — cadastro")
        # Relação pelo ID disponível, evitando assumir que nomes iguais são a mesma pessoa.
        matched = loans[["Nº de Cliente"]].drop_duplicates().merge(
            clients[["ID","Estado Civil"]], left_on="Nº de Cliente",right_on="ID",how="left")
        counts=matched["Estado Civil"].fillna("Sem informação").value_counts().reset_index()
        counts.columns=["Estado Civil","Clientes"]
        if counts.empty: st.info("Sem dados para este gráfico.")
        else:
            fig=px.pie(counts,values="Clientes",names="Estado Civil",hole=.58)
            fig.update_layout(template="plotly_white",margin=dict(l=10,r=10,t=20,b=20))
            st.plotly_chart(fig,use_container_width=True)
    st.info("Retenção e aquisição de clientes requerem comparação cronológica de períodos completos; não são inferidas a partir das linhas de prestações.")


def financial(frame: pd.DataFrame) -> None:
    st.title("Desempenho Financeiro e Crescimento")
    st.caption("Indicadores contratuais, não demonstração de lucro nem reconciliação contabilística.")
    loans=unique_loans(frame)
    a,b,c,d=st.columns(4)
    a.metric("Crédito concedido",money(loans["Quantia Creditada"].sum()))
    b.metric("Prestações previstas",money(frame["Valor a Pagar"].sum()))
    c.metric("Juros contratuais",money(frame["Valor de Juros"].sum()))
    d.metric("Multas registadas",money(frame["Valor de Multa"].sum()))
    st.markdown('<div class="cis-note">Os campos acima são valores guardados em Contractos. Juros contratuais ≠ juros efectivamente recebidos. O valor recebido pode incluir amortização, juros e multa; não é considerado lucro.</div>',unsafe_allow_html=True)
    dated=frame.dropna(subset=["Data de Cobrança"]).copy()
    if dated.empty:
        st.info("Sem datas de cobrança para construir séries.")
        return
    dated["Periodo"]=dated["Data de Cobrança"].dt.to_period("M").astype(str)
    grouped=dated.groupby("Periodo",as_index=False)[["Valor a Pagar","Valor de Juros","Valor de Multa"]].sum().sort_values("Periodo")
    left,right=st.columns(2)
    with left: plot_bar(grouped,"Periodo","Valor a Pagar","Prestações previstas por mês (MZN)")
    with right: plot_bar(grouped,"Periodo","Valor de Juros","Juros contratuais por mês (MZN)")
    st.warning("Reconciliação financeira pendente: o Extracto pode chamar «Receita de Juros» ao pagamento total. Não calcular rentabilidade ou juros recebidos a partir da descrição do movimento.")


def main() -> None:
    if not session_valid():
        login_screen()
        return
    try:
        contracts, clients=load_data()
    except Exception as exc:
        st.error("Consulta bloqueada: a validação da estrutura SQL ou a ligação à base de testes falhou.")
        if str(exc).startswith("SQL_SCHEMA_VALIDATION_FAILED"):
            st.warning("Pré-validação SQL: " + str(exc).split(":", 1)[1].strip())
        return
    contracts=clean_contracts(contracts)
    section, filtered=sidebar_filters(contracts)
    st.caption(f"Utilizador: {st.session_state['cis_user'].get('name','—')} · Perfil: {st.session_state['cis_user'].get('role','—')}")
    if filtered.empty:
        st.info("Não existem registos para os filtros seleccionados.")
        return
    if section==SECTIONS[0]: portfolio(filtered)
    elif section==SECTIONS[1]: clients_dashboard(filtered,clients)
    else: financial(filtered)


if __name__ == "__main__":
    main()
