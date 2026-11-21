import { randomBytes } from 'node:crypto'

export default defineNitroPlugin((nitroApp) => {
  nitroApp.hooks.hook('render:response', (response, { event }) => {
    if (typeof response.body !== 'string' || !String(response.headers?.['content-type']).startsWith('text/html')) return

    const nonce = randomBytes(32).toString('base64')
    const configuredMedia = useRuntimeConfig(event).mediaPublicOrigin
    let mediaOrigin = "'self'"
    if (configuredMedia) {
      const media = new URL(configuredMedia)
      if (media.protocol === 'http:' || media.protocol === 'https:') mediaOrigin = media.origin
    }
    response.body = response.body.replace(/<script\b([^>]*)>([\s\S]*?<\/script\s*>)/gi, (_, attributes: string, contents: string) => {
      const withoutNonce = attributes.replace(/\snonce\s*=\s*(?:"[^"]*"|'[^']*'|[^\s>]+)/gi, '')
      return `<script nonce="${nonce}"${withoutNonce}>${contents}`
    })
    response.headers = {
      ...response.headers,
      'content-security-policy': `default-src 'self'; script-src 'self' 'nonce-${nonce}'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: ${mediaOrigin}; font-src 'self'; connect-src 'self' ${mediaOrigin}; frame-ancestors 'none'; base-uri 'self'; object-src 'none'; form-action 'self'`,
      'cache-control': 'private, no-store, max-age=0',
    }
  })
})
