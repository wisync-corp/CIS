"""Smoke test do Dashboard: corre os três painéis e vários períodos com uma API simulada."""
import json
import random
import threading
from datetime import date, timedelta
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path

from streamlit.testing.v1 import AppTest

APP = str(Path(__file__).resolve().parents[1] / "Dashboard.py")


def fake_data() -> dict:
    rnd = random.Random(7)
    today = date.today()
    names = [f"Mutuário {i}" for i in range(1, 40)]
    rows = []
    for k in range(150):
        cli = rnd.randrange(len(names))
        start = today - timedelta(days=rnd.randrange(0, 600))
        q = rnd.choice([2000, 5000, 12000, 30000, 120000])
        n = rnd.choice([1, 2, 3])
        for j in range(n):
            due = start + timedelta(days=30 * (j + 1))
            st = rnd.choice(["Pago", "Pago em Atraso", "Pagamento Atrasado"]) if due < today else "Pendente"
            rows.append({"Ordem": len(rows) + 1, "Nr do Contracto": f"T-{k:04d}", "Nome do Cliente": names[cli], "Nº de Cliente": cli + 1,
                         "Data de Inicio": start.isoformat(), "Data de Cobrança": due.isoformat(), "Estado": st,
                         "Estado do Contrato": "Em Progresso", "Prazo/Duração": f"{n} Meses", "Quantia Creditada": q,
                         "Valor Pago": q * 1.2 / n if st.startswith("Pago") else 0, "Valor a Pagar": q * 1.2 / n,
                         "Valor de Juros": q * 0.2 / n, "M. Pagamento": "Mensal", "Intervalo": None})
    rows.append({**rows[0], "Ordem": 9999, "Nr do Contracto": "T-SEM-DATAS", "Data de Inicio": None, "Data de Cobrança": None})
    return {"contractos": rows, "clientes": [{"Nome Completo": n, "Estado Civil": rnd.choice(["Casado(a)", None])} for n in names]}


def serve(payload: dict) -> str:
    body = json.dumps(payload).encode()

    class H(BaseHTTPRequestHandler):
        def do_GET(self):
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.end_headers()
            self.wfile.write(body)

        def log_message(self, *a):
            pass

    srv = HTTPServer(("127.0.0.1", 0), H)
    threading.Thread(target=srv.serve_forever, daemon=True).start()
    return f"http://127.0.0.1:{srv.server_port}/"


def run(monkeypatch, payload):
    monkeypatch.setenv("CIS_API_URL", serve(payload))
    at = AppTest.from_file(APP, default_timeout=60)
    at.run()
    return at


def test_all_pages_and_periods(monkeypatch):
    at = run(monkeypatch, fake_data())
    assert not at.exception
    for ano in ["Todos", str(date.today().year), str(date.today().year - 1)]:
        at.selectbox[0].set_value(ano).run()
        for page in ["carteira", "mutuarios", "financeiro"]:
            at.segmented_control(key="pagina").set_value(page).run()
            assert not at.exception, (ano, page, at.exception)


def test_filters_and_empty(monkeypatch):
    at = run(monkeypatch, fake_data())
    at.multiselect(key="f_cli").set_value(["Mutuário 1"]).run()
    assert not at.exception
    at2 = run(monkeypatch, {"contractos": [], "clientes": []})
    assert not at2.exception
