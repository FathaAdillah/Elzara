import { randomBytes } from 'node:crypto'

const TRACEPARENT = /^00-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/

function hex(bytes: number): string {
  let id: string
  do id = randomBytes(bytes).toString('hex')
  while (/^0+$/.test(id))
  return id
}

/** W3C Trace Context (Doc 01 §12): setiap request admin membawa span anak yang diteruskan ke API. */
export default defineNitroPlugin((nitroApp) => {
  nitroApp.hooks.hook('request', (event) => {
    const incoming = getRequestHeader(event, 'traceparent')?.trim().toLowerCase()
    const match = incoming ? TRACEPARENT.exec(incoming) : null
    const traceId = match && !/^0+$/.test(match[1]!) && !/^0+$/.test(match[2]!) ? match[1]! : hex(16)
    event.context.traceparent = `00-${traceId}-${hex(8)}-${match?.[3] ?? '01'}`
  })
})
