import { readFileSync, readdirSync } from "node:fs";
import postcss from "postcss";
import { describe, expect, test } from "vitest";
import { brand, brandThemes, resolveBrandTheme } from "../src/brand/brand.config";

const stylesheet = (name: string) => postcss.parse(readFileSync(new URL(`../src/tokens/${name}.css`, import.meta.url), "utf8"));

function resolveTokens(theme: string, dark: boolean) {
  const values = new Map<string, string>();
  const selectors = [":root", `:root[data-brand-theme="${theme}"]`];
  if (dark) selectors.push(`:root.dark[data-brand-theme="${theme}"]`);

  const apply = (css: postcss.Root) => {
    css.walkRules((rule) => {
      if (!rule.selectors.some((selector) => selectors.includes(selector))) return;
      rule.walkDecls((decl) => { values.set(decl.prop, decl.value); });
    });
    css.walkAtRules("theme", (block) => {
      block.walkDecls((decl) => { values.set(decl.prop, decl.value); });
    });
  };

  apply(stylesheet("primitives"));
  apply(stylesheet("semantic"));
  apply(stylesheet(`presets/${theme}.tokens`));
  if (theme !== "admin" && dark) apply(stylesheet("presets/admin.tokens"));

  function resolve(name: string, seen = new Set<string>()): string {
    const value = values.get(name);
    if (!value || seen.has(name)) throw new Error(`Unresolved token: ${name}`);
    seen.add(name);
    const reference = /^var\((--[\w-]+)\)$/.exec(value);
    return reference ? resolve(reference[1], seen) : value;
  }
  return (name: string) => resolve(`--color-${name}`);
}

function luminance(hex: string) {
  if (!/^#[0-9a-f]{6}$/i.test(hex)) throw new Error(`Expected a six-digit hex color, got ${hex}`);
  const [r, g, b] = [1, 3, 5].map((start) => {
    const channel = parseInt(hex.slice(start, start + 2), 16) / 255;
    return channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
  });
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function contrast(a: string, b: string) {
  const [high, low] = [luminance(a), luminance(b)].sort((x, y) => y - x);
  return (high + 0.05) / (low + 0.05);
}

const textPairs = [
  ["ink", "surface"], ["ink-muted", "surface"], ["ink-muted", "surface-muted"],
  ["ink", "surface-muted"], ["on-brand", "brand"], ["ink", "brand-ink"], ["on-accent", "accent"],
  ["brand-text", "surface"], ["brand-text", "surface-muted"],
  ...["po", "ready", "quote", "success", "warning", "danger", "info"].map((tone) => ["on-status", tone]),
];
const controlPairs = [["focus-ring", "surface"], ["focus-ring", "surface-muted"], ["control-border", "surface"], ["danger", "surface"]];

for (const theme of [...brandThemes, "admin"]) {
  for (const dark of [false, true]) {
    describe(`${theme} (${dark ? "with" : "without"} .dark)`, () => {
      const color = resolveTokens(theme, dark);
      test.each(textPairs)("%s text on %s meets WCAG AA", (fg, bg) => {
        expect(contrast(color(fg), color(bg)), `${color(fg)} on ${color(bg)}`).toBeGreaterThanOrEqual(4.5);
      });
      test.each(controlPairs)("%s indicator against %s meets WCAG AA", (fg, bg) => {
        expect(contrast(color(fg), color(bg)), `${color(fg)} against ${color(bg)}`).toBeGreaterThanOrEqual(3);
      });
    });
  }
}

test("both app stylesheets import the same shared preset entry point", () => {
  const entry = stylesheet("index");
  for (const preset of brandThemes) {
    expect(entry.toString()).toContain(`./presets/${preset}.tokens.css`);
  }
  for (const path of ["apps/web/app/globals.css", "apps/admin/app/assets/css/main.css"]) {
    const css = readFileSync(new URL(`../../../${path}`, import.meta.url), "utf8");
    expect(css).toContain('@import "@elzara/ui/tokens.css"');
  }
});

test("only configured presets are accepted, with a separate admin default", () => {
  expect(resolveBrandTheme(undefined)).toBe(brand.theme);
  expect(resolveBrandTheme(undefined, "admin")).toBe("admin");
  expect(resolveBrandTheme("clean-premium", "admin")).toBe("clean-premium");
  expect(() => resolveBrandTheme("admin")).toThrow("BRAND_THEME");
  expect(() => resolveBrandTheme("unknown", "admin")).toThrow("BRAND_THEME");
});

test("shared primitives do not contain literal colors", () => {
  const components = new URL("../src/components/primitives/", import.meta.url);
  for (const file of readdirSync(components)) {
    expect(readFileSync(new URL(file, components), "utf8"), file).not.toMatch(/#[\da-f]{3,8}\b|\b(?:rgba?|oklch|hsla?)\(/i);
  }
});
