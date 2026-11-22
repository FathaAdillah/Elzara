#!/usr/bin/env python3
"""Check rendered Compose isolation with disposable config, without starting containers."""

import json
import os
from pathlib import Path
import subprocess
from tempfile import TemporaryDirectory


ROOT = Path(__file__).resolve().parents[1]
DOCKER = ROOT / "docker"


def require(condition, message):
    if not condition:
        raise SystemExit(message)


def render(mode, directory, *, override):
    local = mode == "local"
    project = f"elzara-isolation-{mode}"
    domain = "localhost" if local else f"{mode}.example.test"
    scheme = "http" if local else "https"
    edge = f"{scheme}://{domain}"
    admin = f"{scheme}://admin.{domain}"
    storage = f"{scheme}://storage.{domain}"
    env_file = directory / f"{mode}.env"
    tls_dir = directory / f"{mode}-tls"
    tls_dir.mkdir(exist_ok=True)
    fixture = {
        "APP_ENV": mode,
        "APP_DEBUG": str(local).lower(),
        "APP_KEY": f"fixture-{mode}",
        "ELZARA_EDGE_URL": edge,
        "ELZARA_ADMIN_HOST": f"admin.{domain}",
        "ELZARA_ADMIN_ORIGIN": admin,
        "ELZARA_ENV_FILE": env_file,
        "TLS_CERT_DIR": tls_dir,
        "POSTGRES_DB": f"elzara_{mode}",
        "POSTGRES_USER": f"fixture-{mode}",
        "POSTGRES_PASSWORD": f"fixture-db-{mode}",
        "HORIZON_PREFIX": f"elzara_{mode}:",
        "WORKER_MEMORY_LIMIT": "1G",
        "MINIO_PUBLIC_ENDPOINT": storage,
        "AWS_DEFAULT_REGION": "elzara-1",
        "MINIO_ROOT_USER": f"fixture-minio-{mode}",
        "MINIO_ROOT_PASSWORD": f"fixture-minio-password-{mode}",
        "ELZARA_S3_ACCESS_KEY": f"fixture-s3-{mode}",
        "ELZARA_S3_SECRET_KEY": f"fixture-s3-secret-{mode}",
        "ELZARA_STOREFRONT_PROXY_KEY": f"not-a-real-storefront-proxy-key-{mode}",
        "SMTP_HOST": "mailpit" if local else f"smtp.{domain}",
        "SMTP_PORT": "1025" if local else "587",
        "NEXT_PUBLIC_API_BASE_URL": f"{edge}/api/v1",
        "NEXT_PUBLIC_BRAND_THEME": "brand",
    }
    env_file.write_text("".join(f"{key}={value}\n" for key, value in fixture.items()))
    files = ["-f", str(DOCKER / ("compose.local.yaml" if local else "compose.yaml"))]
    if override:
        files += ["-f", str(DOCKER / "profiles" / f"{mode}.yaml")]
    result = subprocess.run(
        ["docker", "compose", "-p", project, "--env-file", str(env_file),
         "--profile", "*", *files, "config", "--format", "json"],
        cwd=ROOT, env={key: os.environ[key] for key in ("PATH", "HOME")},
        capture_output=True, text=True,
    )
    require(result.returncode == 0, f"{mode}: Compose render failed\n{result.stderr}")
    config = json.loads(result.stdout)
    services = config["services"]

    require(config["name"] == project, f"{mode}: wrong Compose project")
    require(("mailpit" in services) == local, f"{mode}: Mailpit must be local only")
    for name in ("postgres", "redis", "minio", "elasticsearch"):
        service = services[name]
        require(not service.get("ports") and not service.get("network_mode"),
                f"{mode}: {name} must not bind directly to the host")
        require(not service.get("container_name"), f"{mode}: {name} must use the project namespace")
        require(all(mount["type"] == "volume" for mount in service["volumes"]),
                f"{mode}: {name} must use project volumes, not host paths")

    for name in ("api", "worker", "scheduler"):
        env = services[name]["environment"]
        require(env["APP_ENV"] == mode, f"{mode}: wrong {name} environment")
        require(local or env["APP_DEBUG"] == "false", f"{mode}: {name} debug enabled")
        for key, value in (
            ("DB_HOST", "postgres"), ("REDIS_HOST", "redis"), ("AWS_ENDPOINT_URL", "http://minio:9000"),
            ("DB_DATABASE", fixture["POSTGRES_DB"]), ("DB_USERNAME", fixture["POSTGRES_USER"]),
            ("DB_PASSWORD", fixture["POSTGRES_PASSWORD"]), ("APP_KEY", fixture["APP_KEY"]),
            ("AWS_ACCESS_KEY_ID", fixture["MINIO_ROOT_USER"]), ("AWS_SECRET_ACCESS_KEY", fixture["MINIO_ROOT_PASSWORD"]),
            ("ELZARA_S3_ACCESS_KEY", fixture["ELZARA_S3_ACCESS_KEY"]),
            ("ELZARA_S3_SECRET_KEY", fixture["ELZARA_S3_SECRET_KEY"]),
            ("APP_URL", edge), ("ELZARA_ADMIN_ORIGIN", admin), ("MINIO_PUBLIC_ENDPOINT", storage),
        ):
            require(env[key] == value, f"{mode}: {name} did not receive the environment-specific {key}")
    require(services["worker"]["environment"]["HORIZON_PREFIX"] == fixture["HORIZON_PREFIX"],
            f"{mode}: wrong Horizon namespace")
    for name, keys in (("postgres", ("POSTGRES_DB", "POSTGRES_USER", "POSTGRES_PASSWORD")),
                       ("minio", ("MINIO_ROOT_USER", "MINIO_ROOT_PASSWORD"))):
        require(all(services[name]["environment"][key] == fixture[key] for key in keys),
                f"{mode}: wrong {name} credentials or database")
    require(services["minio"]["environment"]["MINIO_API_CORS_ALLOW_ORIGIN"] == f"{edge},{admin}",
            f"{mode}: wrong object storage origins")
    require(all(services["nginx"]["environment"][key] == fixture[key]
                for key in ("ELZARA_EDGE_URL", "ELZARA_ADMIN_HOST", "MINIO_PUBLIC_ENDPOINT")),
            f"{mode}: wrong edge host routing")
    require(services["admin"]["environment"]["NUXT_ADMIN_ORIGIN"] == admin, f"{mode}: wrong admin origin")
    require(services["web"]["environment"]["NEXT_PUBLIC_API_BASE_URL"] == fixture["NEXT_PUBLIC_API_BASE_URL"],
            f"{mode}: wrong storefront API origin")
    if not local:
        require(services["api"]["environment"]["CHECKOUT_FAKE_PAYMENT_ENABLED"] == "false",
                f"{mode}: fake payment enabled by default")
    if not local and not override:
        require(not services["nginx"].get("ports"), f"{mode}: Dokploy edge must not bind host ports")

    resources = set()
    for kind in ("networks", "volumes"):
        for name, resource in config[kind].items():
            require(not resource.get("external"), f"{mode}: external {kind} resource {name}")
            resources.add((kind, resource["name"]))
    return resources


def main():
    with TemporaryDirectory(prefix="elzara-compose-isolation-") as folder:
        directory = Path(folder)
        local = render("local", directory, override=False)
        for override in (False, True):
            allocated = local.copy()
            for mode in ("staging", "production"):
                resources = render(mode, directory, override=override)
                require(not resources & allocated, f"{mode}: shared network or volume name")
                allocated.update(resources)
    print("Compose isolation passed (local, Dokploy base and staging/production overrides).")


if __name__ == "__main__":
    main()
