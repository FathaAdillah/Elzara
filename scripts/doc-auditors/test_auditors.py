"""Subprocess mutation tests run the actual scripts from relocated repo copies."""

import shutil
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

from common import ROOT
from expectations import BLUEPRINT_BUSINESS_PATHS, BLUEPRINT_TABLE_COUNT, BLUEPRINT_TABLE_EXCEPTIONS, GATE_H_TABLE_COUNT, IGNORED_DIRS, MENUS, SCHEMA_TABLES


class AuditorsTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.baseline = tempfile.TemporaryDirectory()
        cls.addClassCleanup(cls.baseline.cleanup)
        cls.source = Path(cls.baseline.name) / "repo"
        shutil.copytree(ROOT, cls.source, ignore=shutil.ignore_patterns(*IGNORED_DIRS, "storage", "cache", ".env", ".env.*", ".phpunit.cache", ".deptrac*", ".devin", ".turbo"))

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.repo = Path(self.temp.name) / "repo"
        shutil.copytree(self.source, self.repo)

    def run_auditor(self, name, *args):
        env = dict(os.environ)
        env.pop("ELZARA_BLUEPRINT_PATH", None)
        return subprocess.run([sys.executable, str(self.repo / "scripts/doc-auditors" / name), *args], cwd=self.temp.name, text=True, capture_output=True, env=env)

    def mutate(self, path, old, new):
        target = self.repo / path
        text = target.read_text(encoding="utf-8")
        self.assertIn(old, text)
        target.write_text(text.replace(old, new, 1), encoding="utf-8")

    def assert_defect(self, script, marker, *args):
        result = self.run_auditor(script, *args)
        self.assertNotEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn(marker, result.stdout)
        self.assertNotIn("Traceback", result.stderr)

    def test_clean_scripts(self):
        for script in ("scan_unicode.py", "list_dead_cites.py", "verify_schema.py", "check_layout.py", "audit_sweep3.py", "audit_blueprint_coverage.py"):
            with self.subTest(script=script):
                result = self.run_auditor(script)
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
                if script == "list_dead_cites.py":
                    self.assertEqual(result.stdout, "0 temuan\n")
                if script == "audit_blueprint_coverage.py":
                    self.assertIn("LULUS SEBAGIAN", result.stdout)
                    self.assertIn("Blueprint tidak dibaca", result.stdout)
                    self.assertNotIn("LULUS — COVER", result.stdout)

    def test_unicode_file_argument(self):
        path = self.repo / "docs/01-architecture.md"
        with path.open("a", encoding="utf-8") as stream:
            stream.write("\nLatin lookalike: \u0430\n")
        self.assert_defect("scan_unicode.py", "U+0430", str(path))

    def test_dead_citation(self):
        self.mutate("docs/01-architecture.md", "Doc 11 §8.3", "Doc 11 §999.3")
        self.assert_defect("list_dead_cites.py", "§999.3")

    def test_schema_fk_target(self):
        self.mutate("docs/09-database-schema.md", "REFERENCES users(id)", "REFERENCES missing_users(id)")
        self.assert_defect("verify_schema.py", "unknown FK target missing_users")

    def test_schema_cycle(self):
        self.mutate("docs/09-database-schema.md", "  public_id        varchar(26)", "  cycle_id bigint REFERENCES tickets(id)\n  public_id        varchar(26)")
        self.assert_defect("verify_schema.py", "FK cycle")

    def test_schema_operator_check(self):
        self.mutate("docs/09-database-schema.md", "CHECK (is_admin = false OR is_operator = true)", "CHECK (is_operator)")
        self.assert_defect("verify_schema.py", "CHECK missing")

    def test_layout_missing_directory(self):
        shutil.rmtree(self.repo / "apps/api/app/Modules/Catalog")
        self.assert_defect("check_layout.py", "missing tree path")

    def test_layout_queue(self):
        self.mutate("docker/compose.yaml", "HORIZON_QUEUES: payments,orders", "HORIZON_QUEUES: paymentz,orders")
        self.assert_defect("check_layout.py", "HORIZON_QUEUES differs")

    def test_layout_bucket(self):
        self.mutate("apps/api/config/filesystems.php", "'audit-archives' =>", "'other-archives' =>")
        self.assert_defect("check_layout.py", "bucket disks differ")

    def test_layout_path(self):
        self.mutate("packages/api-contracts/openapi.yaml", "  /health:", "  /unhealthy:")
        self.assert_defect("check_layout.py", "missing Blueprint path /health")

    def test_schema_ownership_count(self):
        self.mutate("docs/09-database-schema.md", f"| **Total** | | **{GATE_H_TABLE_COUNT}** |", f"| **Total** | | **{GATE_H_TABLE_COUNT + 1}** |")
        self.assert_defect("verify_schema.py", "tally")

    def test_schema_legacy_pivot(self):
        self.mutate("docs/09-database-schema.md", "  user_id     bigint NOT NULL REFERENCES users(id)", "  role_user bigint\n  user_id     bigint NOT NULL REFERENCES users(id)")
        self.assert_defect("verify_schema.py", "retired schema identifier role_user")

    def test_schema_unparseable_fk(self):
        self.mutate("docs/09-database-schema.md", "REFERENCES users(id)", "REFERENCES ???")
        self.assert_defect("verify_schema.py", "unparseable REFERENCES")

    def test_citation_continuation(self):
        self.mutate("docs/01-architecture.md", "Doc 11 §8.3", "Doc 11 §8.3/§999")
        self.assert_defect("list_dead_cites.py", "§999")

    def test_citations_empty_corpus(self):
        shutil.rmtree(self.repo / "docs")
        self.assert_defect("list_dead_cites.py", "missing numbered documents")

    def test_schema_missing_input(self):
        (self.repo / "docs/09-database-schema.md").unlink()
        self.assert_defect("verify_schema.py", "No such file")

    def test_unicode_missing_input(self):
        self.assert_defect("scan_unicode.py", "No such file", "missing.md")

    def test_unicode_latin_punctuation(self):
        path = self.repo / "example.md"
        path.write_text("Bahasa Indonesia — naïf · 0 → 1\n", encoding="utf-8")
        result = self.run_auditor("scan_unicode.py", str(path))
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_layout_old_name_outside_rename_clause(self):
        with (self.repo / "docs/11-blueprint-reconciliation.md").open("a", encoding="utf-8") as stream:
            stream.write("\nDeploy apps/commerce-api sekarang.\n")
        self.assert_defect("check_layout.py", "retired application/layout path")

    def test_layout_migration_owner(self):
        source = self.repo / "apps/api/app/Modules/Catalog/Infrastructure/Database/Migrations/0200_Catalog_create_categories_table.php"
        source.rename(source.with_name("0200_Identity_create_categories_table.php"))
        self.assert_defect("check_layout.py", "migration owner does not match directory")

    def test_layout_later_billing_alteration(self):
        path = self.repo / "apps/api/app/Modules/Billing/Infrastructure/Database/Migrations/0845_Billing_add_grace_days_to_invoices.php"
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text("<?php public function up(): void { Schema::table('invoices', function ($t) { $t->integer('grace_days')->nullable(); }); }", encoding="utf-8")
        result = self.run_auditor("check_layout.py")
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_layout_invalid_creation_band(self):
        source = self.repo / "apps/api/app/Modules/Catalog/Infrastructure/Database/Migrations/0200_Catalog_create_categories_table.php"
        source.rename(source.with_name("0845_Catalog_create_categories_table.php"))
        self.assert_defect("check_layout.py", "outside documented sprint band")

    def test_layout_table_band_cannot_override_ownership(self):
        for number in (15, 1600):
            with self.subTest(number=number):
                path = self.repo / f"apps/api/app/Modules/Catalog/Infrastructure/Database/Migrations/{number:04}_Catalog_create_media_table.php"
                path.write_text("<?php public function up(): void { Schema::create('media', function ($t) {}); }", encoding="utf-8")
                self.assert_defect("check_layout.py", "table ownership differs from Doc 09")
                path.unlink()

    def test_layout_alteration_before_creation_band(self):
        path = self.repo / "apps/api/app/Modules/Billing/Infrastructure/Database/Migrations/0245_Billing_add_grace_days_to_invoices.php"
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text("<?php public function up(): void { Schema::table('invoices', function ($t) {}); }", encoding="utf-8")
        self.assert_defect("check_layout.py", "outside documented sprint band")

    def test_layout_ownership_mirror(self):
        self.mutate("docs/01-architecture.md", "| `users`, `addresses`,", "| `users`, `missing_addresses`,")
        self.assert_defect("check_layout.py", "ownership mirror differs for Identity")

    def test_layout_empty_paths(self):
        self.mutate("packages/api-contracts/openapi.yaml", "paths:\n", "paths: {}\n")
        self.assert_defect("check_layout.py", "paths mapping missing")

    def test_unicode_conf_argument(self):
        path = self.repo / "docker/nginx/planted.conf"
        path.write_text("# homoglyph \uac00\n", encoding="utf-8")
        self.assert_defect("scan_unicode.py", "U+AC00", str(path))

    def test_layout_duplicate_basename(self):
        source = self.repo / "apps/api/app/Modules/Catalog/Infrastructure/Database/Migrations/0200_Catalog_create_categories_table.php"
        shutil.copyfile(source, self.repo / "apps/api/database/migrations" / source.name)
        self.assert_defect("check_layout.py", "duplicate global basename")

    def test_layout_missing_npm_lock(self):
        (self.repo / "apps/admin/package-lock.json").unlink()
        self.assert_defect("check_layout.py", "No such file")

    def test_schema_retired_users_kind(self):
        self.mutate("docs/09-database-schema.md", "  public_id        varchar(26)", "  kind varchar(16)\n  public_id        varchar(26)")
        self.assert_defect("verify_schema.py", "users.kind is retired")

    def test_citation_formatted(self):
        self.mutate("docs/01-architecture.md", "Doc 11 §8.3", "Doc **11** §999")
        self.assert_defect("list_dead_cites.py", "§999")

    def append_doc(self, text, path="docs/01-architecture.md"):
        target = self.repo / path
        target.write_text(target.read_text() + "\n" + text + "\n", encoding="utf-8")

    def test_sweep_status_axis(self):
        self.mutate("docs/02-business-flow-customer-features.md", "awaiting_payment · paid", "pending_payment · paid")
        self.assert_defect("audit_sweep3.py", "enum order_status differs")

    def test_sweep_invalid_active_status(self):
        self.append_doc("`order_status = draft` wajib dipakai.")
        self.assert_defect("audit_sweep3.py", "invalid order_status value draft")

    def test_sweep_runbook_file(self):
        (self.repo / "docs/runbooks/queue-stuck.md").unlink()
        self.assert_defect("audit_sweep3.py", "required runbook missing/empty")

    def test_sweep_runbook_mapping(self):
        self.mutate("docs/11-blueprint-reconciliation.md", "| 5 | stuck job/DLQ", "| 5 | incorrect scenario")
        self.assert_defect("audit_sweep3.py", "runbook mappings differ")

    def test_sweep_event_register(self):
        self.mutate("docs/12-api-contract.md", "| `UserRegistered` |", "| `InventedEvent` |")
        self.assert_defect("audit_sweep3.py", "event register differs")

    def test_sweep_unregistered_event(self):
        self.append_doc("Kirim event `UnregisteredThing`.")
        self.assert_defect("audit_sweep3.py", "unregistered event UnregisteredThing")

    def test_sweep_old_name_after_valid_rename(self):
        self.append_doc("`quotes` → `quotations`. Gunakan tabel `quotes` untuk data baru.")
        self.assert_defect("audit_sweep3.py", "retired active name quotes")

    def test_sweep_unquoted_active_old_name(self):
        self.append_doc("Gunakan tabel quotes untuk data baru.")
        self.assert_defect("audit_sweep3.py", "retired active name quotes")

    def test_sweep_arrow_is_not_always_a_rename(self):
        self.append_doc("Ekstraksi modul `Shipping` → `Orders`.")
        self.assert_defect("audit_sweep3.py", "retired active name Shipping")

    def test_sweep_http_path_segment_is_not_a_table(self):
        self.append_doc("| PATCH | `/admin/catalog/variants/{id}` | Ubah varian |")
        result = self.run_auditor("audit_sweep3.py")
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_sweep_table_name_outside_path_still_flagged(self):
        self.append_doc("Simpan stok di tabel variants untuk `/admin/catalog/variants/{id}`.")
        self.assert_defect("audit_sweep3.py", "retired active name variants")

    def test_sweep_adr_explanatory_parenthesis(self):
        self.append_doc("Lihat ADR-005 (kedaluwarsa intent mengikuti gateway).")
        result = self.run_auditor("audit_sweep3.py")
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_sweep_queue_register(self):
        self.mutate("docs/11-blueprint-reconciliation.md", "`reports` · `media`", "`reports` · `extra`")
        self.assert_defect("audit_sweep3.py", "canonical queue register differs")

    def test_sweep_adr_missing(self):
        (self.repo / "docs/adr/ADR-009-waiting-room.md").unlink()
        self.assert_defect("audit_sweep3.py", "ADR inventory")

    def test_sweep_adr_swapped_topic(self):
        self.append_doc("Lihat ADR-007 (RBAC/identitas/dependensi).")
        self.assert_defect("audit_sweep3.py", "inline topic belongs to ADR-012")

    def test_sweep_dod_mapping(self):
        self.mutate("docs/03-sprint-plan.md", '| 10 | "Security review', '| 11 | "Security review')
        self.assert_defect("audit_sweep3.py", "ten mappings missing")

    def test_sweep_test_mapping(self):
        self.mutate("docs/03-sprint-plan.md", "Blueprint §16 baris 6", "Blueprint §16 butir dihapus")
        self.assert_defect("audit_sweep3.py", "Blueprint test 6 unmapped")

    def test_sweep_dead_citation(self):
        self.append_doc("Lihat Doc 11 §999.")
        self.assert_defect("audit_sweep3.py", "bagian tidak ada")

    def test_sweep_unicode(self):
        self.append_doc("Homoglyph \u0430.")
        self.assert_defect("audit_sweep3.py", "U+0430")

    def test_sweep_tree_cross_owner(self):
        self.mutate("docs/01-architecture.md", "# orders, order_items,", "# orders, users, order_items,")
        self.assert_defect("audit_sweep3.py", "Orders tree claims users")

    def test_sweep_tree_parenthesized_cross_owner(self):
        self.mutate("docs/01-architecture.md", "# orders, order_items,", "# orders, order_items, (users)")
        self.assert_defect("audit_sweep3.py", "Orders tree claims users")

    def test_sweep_missing_input(self):
        (self.repo / "docs/09-database-schema.md").unlink()
        self.assert_defect("audit_sweep3.py", "No such file")

    def test_coverage_submenu(self):
        self.mutate("docs/06-admin-dashboard-requirements.md", "Overview, Sales KPI", "Overview, Invented KPI")
        self.assert_defect("audit_blueprint_coverage.py", "S1 verbatim")

    def test_coverage_endpoint_prefix_is_not_a_path(self):
        self.mutate("docs/12-api-contract.md", "| `/products`, `/products/{slug}` |", "| `/products-removed`, `/products/{slug}` |")
        self.assert_defect("audit_blueprint_coverage.py", "S3 missing endpoint /products")

    def test_coverage_contradiction(self):
        self.append_doc("Waiting Room menjamin stok.")
        self.assert_defect("audit_blueprint_coverage.py", "KONTRADIKSI WAITING_ROOM_STOCK")

    def test_coverage_negation_different_sentence(self):
        self.append_doc("Jangan mengklaim hal lain. Waiting Room menjamin stok.")
        self.assert_defect("audit_blueprint_coverage.py", "KONTRADIKSI")

    def test_coverage_negated_claim(self):
        self.append_doc("Tidak benar bahwa Waiting Room menjamin stok.")
        result = self.run_auditor("audit_blueprint_coverage.py")
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_coverage_empty_input(self):
        (self.repo / "docs/06-admin-dashboard-requirements.md").write_text("")
        self.assert_defect("audit_blueprint_coverage.py", "expected exactly one heading")

    def blueprint_fixture(self):
        # A synthetic parser fixture, NOT the missing owner's Blueprint.
        tables = sorted(SCHEMA_TABLES)[:BLUEPRINT_TABLE_COUNT - len(BLUEPRINT_TABLE_EXCEPTIONS)] + sorted(BLUEPRINT_TABLE_EXCEPTIONS)
        text = "# Blueprint fixture\n## 4 Admin menu\n| Group | Submenus |\n|---|---|\n"
        text += "\n".join(f"| {group} | {names} |" for group, names in MENUS.items())
        text += "\n## 6 Tables\n" + "\n".join(f"- `{name}`" for name in tables)
        text += "\n## 7 Endpoints\n" + "\n".join(f"- GET `{path}`" for path in BLUEPRINT_BUSINESS_PATHS)
        path = Path(self.temp.name) / "blueprint.md"
        path.write_text(text, encoding="utf-8")
        return path

    def run_blueprint(self, path):
        env = dict(os.environ, ELZARA_BLUEPRINT_PATH=str(path))
        return subprocess.run([sys.executable, str(self.repo / "scripts/doc-auditors/audit_blueprint_coverage.py")], cwd=self.temp.name, env=env, capture_output=True, text=True)

    def test_coverage_blueprint_source(self):
        result = self.run_blueprint(self.blueprint_fixture())
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn("LULUS — COVER", result.stdout)
        self.assertNotIn("Blueprint tidak dibaca", result.stdout)

    def test_coverage_blueprint_source_defects(self):
        source = self.blueprint_fixture()
        original = source.read_text()
        for old, new, marker in (("Overview, Sales KPI", "Overview, Incorrect KPI", "S1"), ("`staff_users`", "`unknown_blueprint_table`", "S2 missing table"), ("GET `/products`", "GET `/unknown-path`", "S3"), ("GET `/products`", "GET `/health`", "S3"), ("## 7 Endpoints", "## 7 Endpoints\n- GET `/health`", "S3"), ("## 6 Tables", "## 9 Tables", "section 6 missing")):
            with self.subTest(defect=marker):
                source.write_text(original.replace(old, new), encoding="utf-8")
                result = self.run_blueprint(source)
                self.assertNotEqual(result.returncode, 0, result.stdout + result.stderr)
                self.assertIn(marker, result.stdout)
                self.assertNotIn("Traceback", result.stderr)

    def test_coverage_blueprint_missing_file(self):
        result = self.run_blueprint(Path(self.temp.name) / "missing.md")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("No such file", result.stdout)

    def test_wrapper_external_override_without_tests(self):
        external = Path(self.temp.name) / "external"
        external.mkdir()
        for name in ("verify_schema.py", "audit_sweep3.py", "audit_blueprint_coverage.py", "check_layout.py", "list_dead_cites.py", "scan_unicode.py"):
            (external / name).write_text("print('external auditor')\n", encoding="utf-8")
        (self.repo / "scripts/doc-auditors/test_auditors.py").write_text("print('IN_REPO_TESTS_EXECUTED')\n", encoding="utf-8")
        env = dict(os.environ, PYTHON=sys.executable, ELZARA_AUDITORS_DIR=str(external))
        result = subprocess.run(["bash", str(self.repo / "scripts/verify-docs.sh")], env=env, cwd=self.temp.name, capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn("IN_REPO_TESTS_EXECUTED", result.stdout)
        self.assertFalse((external / "test_auditors.py").exists())

    def test_wrapper_external_failure_is_not_hidden(self):
        external = Path(self.temp.name) / "external"
        external.mkdir()
        (self.repo / "scripts/doc-auditors/test_auditors.py").write_text("print('IN_REPO_TESTS_EXECUTED')\n", encoding="utf-8")
        env = dict(os.environ, PYTHON=sys.executable, ELZARA_AUDITORS_DIR=str(external))
        result = subprocess.run(["bash", str(self.repo / "scripts/verify-docs.sh")], env=env, cwd=self.temp.name, capture_output=True, text=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("IN_REPO_TESTS_EXECUTED", result.stdout)


if __name__ == "__main__":
    unittest.main()
