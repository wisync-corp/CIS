"""Validação SQL prévia, apenas leitura, sem expor dados pessoais."""
from __future__ import annotations
from dataclasses import dataclass
from sqlalchemy import text

REQUIRED = {
    "Contractos": {
        "Nr do Contracto", "Nº da Parcela", "Nº de Prestações", "Nº de Cliente",
        "Nome do Cliente", "Data de Inicio", "Data de Cobrança", "Prazo/Duração",
        "M. Pagamento", "Intervalo", "Quantia Creditada", "Capital", "Amortização",
        "Valor de Juros", "Valor de Multa", "Valor a Pagar", "Valor Pago",
        "Estado", "Estado do Contrato",
    },
    "Clientes": {"ID", "Nome Completo", "Estado Civil"},
}

@dataclass(frozen=True)
class Validation:
    ok: bool
    details: tuple[str, ...]

def validate_schema(engine) -> Validation:
    """Bloqueia o dashboard quando a estrutura SQL esperada não existe.

    Faz apenas SELECT em INFORMATION_SCHEMA; não tenta corrigir tabelas.
    """
    sql = text("""
        SELECT TABLE_NAME, COLUMN_NAME
          FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME IN ('Contractos', 'Clientes')
    """)
    with engine.connect() as connection:
        rows = connection.execute(sql).fetchall()
    found = {table: set() for table in REQUIRED}
    for table, column in rows:
        if table in found:
            found[table].add(column)
    messages = []
    for table, required_columns in REQUIRED.items():
        missing = sorted(required_columns - found[table])
        if missing:
            messages.append(f"{table}: campos obrigatórios em falta: {', '.join(missing)}")
    return Validation(not messages, tuple(messages))
