from __future__ import annotations


def main() -> None:
    try:
        import playwright  # noqa: F401
    except Exception as exc:  # pragma: no cover
        raise SystemExit(f"playwright-import-failed: {exc!r}") from exc
    print("playwright-ok")


if __name__ == "__main__":
    main()
