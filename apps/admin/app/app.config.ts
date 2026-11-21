export default defineAppConfig({
  ui: {
    colors: {
      primary: 'elzara',
      neutral: 'slate',
    },
    button: { slots: { base: 'min-h-touch min-w-touch' } },
    input: { slots: { base: 'min-h-touch ring-control-border focus-visible:ring-focus-ring' } },
    card: { slots: { root: 'rounded-card shadow-card' } },
    badge: { slots: { base: 'rounded-badge' } },
    modal: {
      slots: {
        overlay: 'z-modal bg-overlay motion-reduce:animate-none',
        content: 'z-modal rounded-card shadow-card motion-reduce:animate-none',
      },
    },
    toast: { slots: { root: 'rounded-card shadow-card motion-reduce:animate-none motion-reduce:transition-none' } },
    toaster: { slots: { viewport: 'z-toast' } },
  },
})
