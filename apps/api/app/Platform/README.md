# Platform — shared kernel, bukan modul domain

Platform memuat mekanisme lintas modul (empat sumbu status, ApiProblem RFC 7807, state-machine runner, outbox writer + dispatcher tujuh antrian) dan memiliki trio reliability `outbox_events`/`inbox_events`/`idempotency_keys`; Platform tidak memuat logika bisnis dan tidak memegang tabel milik modul mana pun (Doc 01 §3.1, B1).
