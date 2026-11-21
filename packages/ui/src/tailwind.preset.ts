// Peta token -> utilitas Tailwind v4. Nol nilai warna di sini: semuanya menunjuk variabel CSS (Doc 04 §2.1).
export const uiPreset = {
  theme: {
    extend: {
      height: {
        "button-sm": "var(--button-height-sm)",
        button: "var(--button-height)",
        "button-lg": "var(--button-height-lg)",
      },
      minHeight: { touch: "var(--touch-target-min)" },
      minWidth: { touch: "var(--touch-target-min)" },
      fontSize: {
        xs: "var(--text-xs)",
        sm: "var(--text-sm)",
        base: "var(--text-base)",
        lg: "var(--text-lg)",
        xl: "var(--text-xl)",
        "2xl": "var(--text-2xl)",
      },
      width: { drawer: "var(--cart-drawer-width)" },
      maxWidth: { content: "var(--content-max)" },
      borderRadius: { card: "var(--radius-card)", badge: "var(--badge-radius)" },
      boxShadow: { card: "var(--shadow-card)" },
      spacing: {
        gutter: "var(--grid-gutter)",
        "gutter-lg": "var(--grid-gutter-lg)",
        1: "var(--space-1)",
        2: "var(--space-2)",
        3: "var(--space-3)",
        4: "var(--space-4)",
        6: "var(--space-6)",
        8: "var(--space-8)",
      },
      zIndex: { drawer: "var(--z-drawer)", modal: "var(--z-modal)", toast: "var(--z-toast)" },
      transitionDuration: { fast: "var(--motion-fast)", base: "var(--motion-base)" },
    },
  },
};

export default uiPreset;
