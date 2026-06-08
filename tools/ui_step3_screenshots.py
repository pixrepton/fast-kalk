from __future__ import annotations

import os
from pathlib import Path

from playwright.sync_api import Page, sync_playwright


URL = os.environ.get("FAST_KALK_URL", "http://127.0.0.1:8090/?page_id=6")


def goto_step3(page: Page) -> None:
    page.goto(URL, wait_until="networkidle")
    root = page.locator("#topinstal-lead-widget-root")
    root.wait_for(state="visible", timeout=15_000)

    # Step 1: pick 3 dropdowns to trigger auto-advance.
    triggers = root.locator(".tilw-select-trigger")
    for i in range(3):
        triggers.nth(i).scroll_into_view_if_needed()
        triggers.nth(i).click()
        root.locator(".tilw-select-menu.is-open").wait_for(state="visible", timeout=5_000)
        root.locator(".tilw-select-menu.is-open .tilw-select-option").first.click()

    # Ensure auto-advance happens (there's a 1s timer).
    page.wait_for_timeout(1200)

    # Step 2: skip remaining questions -> show pre-result.
    page.get_by_role("button", name="Pomiń pozostałe pytania — pokaż wynik").click()

    # Step 3: wait until result + email section are visible.
    root.locator(".tilw-result-compact").wait_for(state="visible", timeout=20_000)
    root.locator(".tilw-email-compact").wait_for(state="visible", timeout=20_000)


def main() -> None:
    out_dir = Path("tools/_artifacts")
    out_dir.mkdir(parents=True, exist_ok=True)

    viewports = [
        ("mobile", {"width": 390, "height": 844}),
        ("desktop", {"width": 1280, "height": 720}),
    ]

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        try:
            for name, vp in viewports:
                ctx = browser.new_context(viewport=vp)
                page = ctx.new_page()
                goto_step3(page)

                widget = page.locator("#topinstal-lead-widget-root")
                widget.screenshot(path=str(out_dir / f"step3-{name}.png"))

                page.screenshot(path=str(out_dir / f"step3-{name}-full.png"), full_page=True)
                ctx.close()
        finally:
            browser.close()

    print(f"OK: saved screenshots to {out_dir}")


if __name__ == "__main__":
    main()
