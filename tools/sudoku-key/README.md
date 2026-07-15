# sudoku-key

Fboard panel helper for Sudoku master key generation and per-user available-key derivation.

Build:

```bash
cd tools/sudoku-key
go build -o ../../bin/sudoku-key .
```

Or:

```bash
go build -C tools/sudoku-key -o bin/sudoku-key .
```

The panel looks for `base_path('bin/sudoku-key')` (or `FBOARD_SUDOKU_KEY_BIN`).
