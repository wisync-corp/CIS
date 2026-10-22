"""Testes independentes de servidor para o bloqueio SQL."""
from schema_validation import validate_schema, REQUIRED

class Result:
    def __init__(self, rows): self.rows = rows
    def fetchall(self): return self.rows
class Connection:
    def __init__(self, rows): self.rows = rows
    def execute(self, sql):
        assert "INFORMATION_SCHEMA.COLUMNS" in str(sql)
        assert "SELECT" in str(sql).upper()
        return Result(self.rows)
    def __enter__(self): return self
    def __exit__(self, *_): return False
class Engine:
    def __init__(self, rows): self.rows = rows
    def connect(self): return Connection(self.rows)

def test_complete_schema():
    rows = [(table, col) for table, cols in REQUIRED.items() for col in cols]
    assert validate_schema(Engine(rows)).ok

def test_missing_column_prevents_dashboard():
    rows = [(table, col) for table, cols in REQUIRED.items() for col in cols
            if not (table == "Contractos" and col == "Valor de Juros")]
    result = validate_schema(Engine(rows))
    assert not result.ok
    assert any("Valor de Juros" in message for message in result.details)

def test_no_schema_prevents_dashboard():
    assert not validate_schema(Engine([])).ok
