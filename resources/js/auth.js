/*
 * The auth pages' enhancements, in plain JavaScript: these pages load neither Livewire nor Alpine. Every form
 * works without this file; it only adds the six-box code display, the show/hide password button, the switch to
 * a recovery code and the recovery-code tools. Texts come from data attributes rendered (translated) by Blade.
 * Nothing here stores, sends or logs a value.
 */

const LENGTH = 6

/**
 * The one-time code: one real input (name, pattern, maxlength, autocomplete="one-time-code") over six boxes
 * that only show its digits. Same markup and classes as x-ui.otp-input's Alpine variant.
 */
export function otp(root) {
    const input = root.querySelector('[data-otp-input]')
    const slots = [...root.querySelectorAll('[data-slot]')]
    const clean = (text) => String(text ?? '').replace(/\D/g, '').slice(0, LENGTH)
    let focused = false

    const caretToEnd = () => {
        const end = input.value.length
        if (input.selectionStart !== end || input.selectionEnd !== end) input.setSelectionRange(end, end)
    }

    const render = () => {
        const cleaned = clean(input.value)
        if (cleaned !== input.value) input.value = cleaned
        const active = focused ? Math.min(cleaned.length, LENGTH - 1) : -1
        slots.forEach((slot, i) => {
            slot.querySelector('[data-otp-char]').textContent = cleaned[i] ?? ''
            slot.toggleAttribute('data-active', i === active)
            slot.toggleAttribute('data-filled', !!cleaned[i])
            slot.querySelector('[data-otp-caret]').hidden = !(i === active && !cleaned[i])
        })
        return cleaned
    }

    input.addEventListener('input', () => {
        if (render().length === LENGTH) root.dispatchEvent(new CustomEvent('otp-complete', { bubbles: true, detail: { value: input.value } }))
    })
    // A pasted code is cleaned first: maxlength would otherwise cut "123 456" or "12-34-56" before it is read.
    input.addEventListener('paste', (event) => {
        event.preventDefault()
        input.value = clean((event.clipboardData || window.clipboardData)?.getData('text'))
        input.dispatchEvent(new Event('input', { bubbles: true }))
        requestAnimationFrame(caretToEnd)
    })
    input.addEventListener('focus', () => { focused = true; render(); caretToEnd() })
    input.addEventListener('blur', () => { focused = false; render() })
    for (const type of ['click', 'keyup', 'select']) input.addEventListener(type, caretToEnd)

    root.dataset.enhanced = ''
    focused = document.activeElement === input
    render()
}

/** Show or hide a password. The button stays hidden without JavaScript, where it could do nothing. */
export function passwordToggle(button) {
    const input = document.getElementById(button.getAttribute('aria-controls'))
    const update = (shown) => {
        input.type = shown ? 'text' : 'password'
        button.setAttribute('aria-label', shown ? button.dataset.labelHide : button.dataset.labelShow)
        button.querySelector('[data-icon="show"]').hidden = shown
        button.querySelector('[data-icon="hide"]').hidden = !shown
    }
    button.addEventListener('click', () => {
        update(input.type === 'password')
        input.focus()
    })
    // A shown password is hidden again before the form leaves, so the browser does not keep it as plain text.
    input.form?.addEventListener('submit', () => update(false))
    update(false)
    button.hidden = false
}

/**
 * Two panels, one shown at a time (authenticator code / recovery code). Without JavaScript both stay visible and
 * the switch links are anchors to the other panel. With disable="true", the hidden panel's fields are disabled
 * so they are not sent (one form holding both, as on /security/confirm).
 */
export function authSwitch(root) {
    const panels = [...root.querySelectorAll('[data-switch-panel]')]
    const disable = root.dataset.disableHidden === 'true'
    const show = (name, focus) => {
        for (const panel of panels) {
            const active = panel.dataset.switchPanel === name
            panel.hidden = !active
            if (disable) panel.querySelectorAll('input').forEach((field) => { field.disabled = !active })
            if (active && focus) panel.querySelector('input:not([type=hidden])')?.focus()
        }
    }
    root.querySelectorAll('[data-switch-to]').forEach((link) => link.addEventListener('click', (event) => {
        event.preventDefault()
        show(link.dataset.switchTo, true)
    }))
    const initial = root.dataset.switchInitial || panels[0]?.dataset.switchPanel
    // The first panel's field has autofocus; another panel shown first (after its error) takes the focus.
    show(initial, initial !== panels[0]?.dataset.switchPanel)
    root.dataset.enhanced = ''
}

/** Copy, download (.txt made here), print, and the "I have saved them" check before continuing. */
export function recoveryCodes(root) {
    const codes = [...root.querySelectorAll('[data-recovery-list] li')].map((el) => el.textContent.trim())
    const status = root.querySelector('[data-recovery-status]')
    const say = (text) => { status.textContent = ''; requestAnimationFrame(() => { status.textContent = text }) }

    // The Clipboard API exists only on HTTPS and localhost. Elsewhere (http://hr-system.test) the codes are
    // selected instead, ready for Ctrl+C.
    root.querySelector('[data-recovery-copy]')?.addEventListener('click', async () => {
        if (navigator.clipboard && window.isSecureContext) {
            try {
                await navigator.clipboard.writeText(codes.join('\n'))
                say(root.dataset.textCopied)
                return
            } catch (e) {
                // Refused (permissions): fall back to the selection below.
            }
        }
        const list = root.querySelector('[data-recovery-list]')
        const range = document.createRange()
        range.selectNodeContents(list)
        const selection = window.getSelection()
        selection.removeAllRanges()
        selection.addRange(range)
        list.focus()
        say(root.dataset.textSelected)
    })

    root.querySelector('[data-recovery-download]')?.addEventListener('click', () => {
        const text = [root.dataset.fileTitle, root.dataset.fileNote, '', ...codes, ''].join('\r\n')
        const url = URL.createObjectURL(new Blob([text], { type: 'text/plain;charset=utf-8' }))
        const link = Object.assign(document.createElement('a'), { href: url, download: root.dataset.fileName })
        document.body.append(link)
        link.click()
        link.remove()
        setTimeout(() => URL.revokeObjectURL(url), 0)
        say(root.dataset.textDownloaded)
    })

    root.querySelector('[data-recovery-print]')?.addEventListener('click', () => window.print())

    const ack = root.querySelector('[data-recovery-ack]')
    const next = root.querySelector('[data-recovery-continue]')
    if (ack && next) {
        const update = () => {
            next.setAttribute('aria-disabled', String(!ack.checked))
            next.toggleAttribute('data-disabled', !ack.checked)
        }
        next.addEventListener('click', (event) => {
            if (!ack.checked) {
                event.preventDefault()
                ack.focus()
            }
        })
        ack.addEventListener('change', update)
        update()
    }

    root.querySelectorAll('[data-recovery-tools]').forEach((el) => { el.hidden = false })
}

/**
 * The main button shows a spinner while its form is being sent, and a second submit is ignored. The browser's own
 * validation runs first (no submit event for an invalid form). A page restored from the back/forward cache is reset.
 */
export function submitState(form) {
    form.addEventListener('submit', (event) => {
        if (form.hasAttribute('data-submitting')) {
            event.preventDefault()
            return
        }
        form.setAttribute('data-submitting', '')
        const button = event.submitter?.matches('[data-submit]') ? event.submitter : form.querySelector('[data-submit]')
        button?.setAttribute('data-loading', '')
        button?.setAttribute('aria-busy', 'true')
    })
}

window.addEventListener('pageshow', (event) => {
    if (!event.persisted) return
    document.querySelectorAll('form[data-submitting]').forEach((form) => form.removeAttribute('data-submitting'))
    document.querySelectorAll('[data-submit][data-loading]').forEach((button) => {
        button.removeAttribute('data-loading')
        button.removeAttribute('aria-busy')
    })
})

function start() {
    document.querySelectorAll('[data-otp]:not([data-enhanced])').forEach(otp)
    document.querySelectorAll('[data-password-toggle]').forEach(passwordToggle)
    document.querySelectorAll('[data-auth-switch]').forEach(authSwitch)
    document.querySelectorAll('[data-recovery-codes]').forEach(recoveryCodes)
    document.querySelectorAll('form:has([data-submit])').forEach(submitState)
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start)
else start()
