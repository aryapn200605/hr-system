/*
 * Form components of the redesign (Phase 15b), written as Alpine data after the reference's Select,
 * Command, Popover, Calendar and input OTP (shadcn-admin, MIT; see THIRD-PARTY-NOTICES.md). Alpine and its
 * anchor and focus plugins come from Livewire's bundle, so nothing here is a new package.
 *
 * Every component works in two modes: a plain form (hidden inputs with the field name) and Livewire
 * (wire:model on the root, bound through x-modelable). Option lists and labels are read from
 * <script type="application/json"> elements that the server renders, so a Livewire update reaches them.
 * Dates never come from the browser's clock: "today" is rendered by the server (WIB).
 */

const readJson = (text, fallback) => {
    try {
        return text ? JSON.parse(text) : fallback
    } catch (e) {
        return fallback
    }
}

// Lists the server renders as data-* attributes on the component's root. A Livewire update morphs the
// attribute; the observer copies it into Alpine's reactive state.
const follow = (component, attributes) => {
    let read = () => {
        for (let [attribute, property, fallback] of attributes) {
            component[property] = readJson(component.$root.getAttribute(attribute), fallback)
        }
    }
    read()
    let observer = new MutationObserver(read)
    observer.observe(component.$root, { attributes: true, attributeFilter: attributes.map((a) => a[0]) })
    return observer
}

const keep = (component) => {
    let dom = { root: component.$root }
    for (let name of ['trigger', 'search', 'listbox', 'status', 'grid', 'panel']) dom[name] = component.$refs[name]
    return dom
}

// Place an open panel under its button (above when there is more room there), inside the screen, with
// position: fixed so no overflow box clips it, and follow scrolling and resizing until stop() is called.
// Alpine's x-anchor was tried first: with the fixed strategy it measured from the page's content panel,
// not the viewport, and left panels at stale positions after a scroll.
const float = (trigger, panel) => {
    let frame = null
    let place = () => {
        frame = null
        let gap = 4
        let margin = 8
        let width = document.documentElement.clientWidth
        let height = window.innerHeight
        let t = trigger.getBoundingClientRect()
        panel.style.position = 'fixed'
        panel.style.maxWidth = (width - margin * 2) + 'px'
        panel.style.minWidth = Math.min(t.width, width - margin * 2) + 'px'
        panel.style.maxHeight = ''
        let p = panel.getBoundingClientRect()
        let below = height - t.bottom - gap - margin
        let above = t.top - gap - margin
        let up = p.height > below && above > below
        let room = up ? above : below
        if (p.height > room) panel.style.maxHeight = Math.max(room, 120) + 'px'
        let h = Math.min(p.height, Math.max(room, 120))
        panel.style.left = Math.max(margin, Math.min(t.left, width - margin - p.width)) + 'px'
        panel.style.top = (up ? t.top - gap - h : t.bottom + gap) + 'px'
    }
    let schedule = () => { if (frame === null) frame = requestAnimationFrame(place) }
    // A panel that grows or shrinks (search results, another month) is placed again.
    let observer = new ResizeObserver(schedule)
    place()
    observer.observe(panel)
    window.addEventListener('scroll', schedule, true)
    window.addEventListener('resize', schedule)
    return () => {
        if (frame !== null) cancelAnimationFrame(frame)
        observer.disconnect()
        window.removeEventListener('scroll', schedule, true)
        window.removeEventListener('resize', schedule)
    }
}

const normalize = (text) => String(text ?? '').toLocaleLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')

const format = (template, count) => String(template).replace(':count', count)

/* ------------------------------------------------------------------------------------------------------ */
/* Select: single or multiple, with or without a search box, options local or searched on the server.     */
/* ------------------------------------------------------------------------------------------------------ */

export function uiSelect(config) {
    // Elements are kept from init: a magic such as $refs called from an option or a day that was
    // re-rendered away would resolve from a detached element.
    let dom = {}
    let unfloat = () => {}

    return {
        id: config.id,
        multiple: !! config.multiple,
        searchable: !! config.searchable,
        remote: config.remote || null,
        value: config.multiple ? [] : '',
        open: false,
        query: '',
        active: -1,
        typed: '',
        typedAt: 0,
        found: [],
        loading: false,
        searchTimer: null,
        searchToken: 0,
        seen: {},
        local: [],
        known: {},

        init() {
            dom = keep(this)
            follow(this, [['data-options', 'local', []], ['data-labels', 'known', {}]])
            this.value = this.clean(config.value)
            // A value set from outside (Livewire, x-model) is cleaned the same way, so an ID sent as a number
            // still matches its option.
            this.$watch('value', (value) => {
                let cleaned = this.clean(value)
                if (JSON.stringify(cleaned) !== JSON.stringify(value)) this.value = cleaned
            })
        },

        clean(value) {
            if (this.multiple) {
                let list = Array.isArray(value) ? value : (value === null || value === undefined || value === '' ? [] : [value])
                return [...new Set(list.filter((v) => typeof v === 'string' || typeof v === 'number').map(String))]
            }
            return value === null || value === undefined ? '' : String(value)
        },

        // Options rendered by the server, or the last results of a server search.
        get options() {
            return this.remote ? this.found : this.local
        },

        get labels() {
            return { ...this.seen, ...this.known }
        },

        get filtered() {
            if (this.remote || ! this.searchable || this.query.trim() === '') return this.options
            let words = normalize(this.query).split(/\s+/).filter(Boolean)
            return this.options.filter((o) => {
                let text = normalize(o.label + ' ' + (o.hint || ''))
                return words.every((w) => text.includes(w))
            })
        },

        labelOf(value) {
            let option = this.options.find((o) => o.value === value)
            return option ? option.label : (this.labels[value] ?? value)
        },

        isSelected(value) {
            return this.multiple ? this.value.includes(value) : this.value === value
        },

        get selectedList() {
            return this.multiple ? this.value : (this.value === '' ? [] : [this.value])
        },

        get display() {
            if (this.multiple) {
                if (this.value.length === 0) return ''
                if (this.value.length > config.summaryAfter) return format(config.text.selected, this.value.length)
                return this.value.map((v) => this.labelOf(v)).join(', ')
            }
            let option = this.options.find((o) => o.value === this.value)
            if (option) return option.label
            return this.value === '' ? '' : (this.labels[this.value] ?? '')
        },

        optionId(index) {
            return this.id + '-option-' + index
        },

        get activeId() {
            return this.open && this.active >= 0 && this.active < this.filtered.length ? this.optionId(this.active) : null
        },

        // Opening, closing and focus ------------------------------------------------------------------

        toggle() {
            this.open ? this.close(true) : this.show()
        },

        show(text = '') {
            if (this.open || dom.trigger.disabled) return
            this.open = true
            this.query = text
            let index = this.filtered.findIndex((o) => this.isSelected(o.value))
            this.active = index >= 0 ? index : this.firstEnabled(0, 1)
            if (this.remote) this.search(true)
            this.$nextTick(() => {
                unfloat = float(dom.trigger, dom.panel)
                if (dom.search) {
                    dom.search.focus()
                    let end = dom.search.value.length
                    dom.search.setSelectionRange(end, end)
                } else if (dom.listbox && dom.listbox.tabIndex === 0) {
                    dom.listbox.focus()
                }
                this.scrollToActive()
            })
        },

        close(focusTrigger = false) {
            if (! this.open) return
            this.open = false
            unfloat()
            this.query = ''
            this.typed = ''
            if (focusTrigger) this.$nextTick(() => dom.trigger.focus())
        },

        onFocusOut(event) {
            if (this.open && ! dom.root.contains(event.relatedTarget)) this.close(false)
        },

        // Choosing -------------------------------------------------------------------------------------

        choose(option) {
            if (! option || option.disabled) return
            this.seen = { ...this.seen, [option.value]: option.label }
            if (this.multiple) {
                this.value = this.isSelected(option.value)
                    ? this.value.filter((v) => v !== option.value)
                    : [...this.value, option.value]
                this.announce()
                return
            }
            this.value = option.value
            this.close(true)
        },

        remove(value) {
            this.value = this.value.filter((v) => v !== value)
            this.announce()
            this.$nextTick(() => {
                let chips = dom.root.querySelectorAll('[data-chip-remove]')
                chips.length ? chips[0].focus() : dom.trigger.focus()
            })
        },

        selectAll() {
            let add = this.filtered.filter((o) => ! o.disabled).map((o) => o.value)
            this.value = [...new Set([...this.value, ...add])]
            this.announce()
        },

        clear() {
            this.value = this.multiple ? [] : ''
            this.announce()
        },

        announce() {
            if (dom.status) dom.status.textContent = format(config.text.selected, this.value.length)
        },

        // Keyboard -------------------------------------------------------------------------------------

        firstEnabled(from, step) {
            let list = this.filtered
            for (let i = from; i >= 0 && i < list.length; i += step) {
                if (! list[i].disabled) return i
            }
            return -1
        },

        move(step) {
            let list = this.filtered
            if (list.length === 0) return
            let next = this.firstEnabled(this.active + step, step)
            if (next !== -1) this.active = next
            this.scrollToActive()
        },

        scrollToActive() {
            this.$nextTick(() => {
                let el = this.activeId ? document.getElementById(this.activeId) : null
                if (el) el.scrollIntoView({ block: 'nearest' })
            })
        },

        // Keys on the closed or open trigger (the select-only combobox) and on the list or search box.
        onKey(event) {
            let key = event.key
            if (! this.open) {
                if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(key)) {
                    event.preventDefault()
                    this.show()
                } else if (key.length === 1 && ! event.ctrlKey && ! event.metaKey && ! event.altKey) {
                    if (this.searchable || this.remote) {
                        event.preventDefault()
                        this.show(key)
                    } else {
                        this.show()
                        this.typeahead(key)
                    }
                }
                return
            }
            switch (key) {
                case 'ArrowDown': event.preventDefault(); this.move(1); break
                case 'ArrowUp': event.preventDefault(); this.move(-1); break
                case 'Home':
                    if (event.target === dom.search) return
                    event.preventDefault(); this.active = this.firstEnabled(0, 1); this.scrollToActive(); break
                case 'End':
                    if (event.target === dom.search) return
                    event.preventDefault(); this.active = this.firstEnabled(this.filtered.length - 1, -1); this.scrollToActive(); break
                case 'PageDown': event.preventDefault(); this.move(8); break
                case 'PageUp': event.preventDefault(); this.move(-8); break
                case 'Enter':
                    event.preventDefault()
                    this.choose(this.filtered[this.active])
                    break
                case ' ':
                    if (event.target === dom.search) return
                    event.preventDefault()
                    this.choose(this.filtered[this.active])
                    break
                case 'Escape':
                    event.preventDefault()
                    event.stopPropagation()
                    this.close(true)
                    break
                case 'Tab':
                    this.close(false)
                    break
                default:
                    if (event.target !== dom.search && key.length === 1 && ! event.ctrlKey && ! event.metaKey && ! event.altKey) {
                        this.typeahead(key)
                    }
            }
        },

        // Type to find in a list without a search box: the first option whose label starts with what was typed.
        typeahead(key) {
            let now = Date.now()
            this.typed = now - this.typedAt > 700 ? key : this.typed + key
            this.typedAt = now
            let text = normalize(this.typed)
            let list = this.filtered
            let start = this.typed.length === 1 ? this.active + 1 : this.active
            for (let n = 0; n < list.length; n++) {
                let i = (Math.max(start, 0) + n) % list.length
                if (! list[i].disabled && normalize(list[i].label).startsWith(text)) {
                    this.active = i
                    this.scrollToActive()
                    return
                }
            }
        },

        onQuery() {
            this.active = this.firstEnabled(0, 1)
            if (this.remote) this.search(false)
        },

        // Server search through a Livewire method that returns at most a few options the user may see.
        search(now) {
            clearTimeout(this.searchTimer)
            let run = () => {
                let token = ++this.searchToken
                this.loading = true
                Promise.resolve(this.$wire[this.remote](this.query.trim()))
                    .then((results) => {
                        if (token !== this.searchToken) return
                        this.found = Array.isArray(results) ? results.map((o) => ({ ...o, value: String(o.value) })) : []
                        let index = this.found.findIndex((o) => this.isSelected(o.value))
                        this.active = index >= 0 ? index : this.firstEnabled(0, 1)
                    })
                    .catch(() => {
                        if (token === this.searchToken) this.found = []
                    })
                    .finally(() => {
                        if (token === this.searchToken) this.loading = false
                    })
            }
            now ? run() : (this.searchTimer = setTimeout(run, 300))
        },
    }
}

/* ------------------------------------------------------------------------------------------------------ */
/* Date picker: one date, or a range for filters. Dates are plain yyyy-mm-dd strings handled in UTC, so   */
/* the browser's time zone and clock never shift them.                                                    */
/* ------------------------------------------------------------------------------------------------------ */

const ISO = /^\d{4}-\d{2}-\d{2}$/

const parse = (text) => {
    if (! ISO.test(text || '')) return null
    let [y, m, d] = text.split('-').map(Number)
    let date = new Date(Date.UTC(y, m - 1, d))
    return date.getUTCFullYear() === y && date.getUTCMonth() === m - 1 && date.getUTCDate() === d ? date : null
}

const iso = (date) => date.toISOString().slice(0, 10)

const addDays = (text, days) => {
    let date = parse(text)
    date.setUTCDate(date.getUTCDate() + days)
    return iso(date)
}

const addMonths = (text, months) => {
    let date = parse(text)
    let day = date.getUTCDate()
    date.setUTCDate(1)
    date.setUTCMonth(date.getUTCMonth() + months)
    let last = new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth() + 1, 0)).getUTCDate()
    date.setUTCDate(Math.min(day, last))
    return iso(date)
}

// Monday is 0, Sunday is 6.
const weekday = (text) => (parse(text).getUTCDay() + 6) % 7

export function uiDatePicker(config) {
    let dom = {}
    let unfloat = () => {}
    let locale = document.documentElement.lang || 'en'

    return {
        id: config.id,
        range: !! config.range,
        today: config.today,
        min: config.min || null,
        max: config.max || null,
        weekends: !! config.disableWeekends,
        blocked: [],
        value: '',
        start: '',
        end: '',
        open: false,
        month: '',
        focused: '',
        picking: false,

        init() {
            dom = keep(this)
            follow(this, [['data-disabled-dates', 'blocked', []]])
            this.value = parse(config.value) ? config.value : ''
            if (this.range) {
                this.start = parse(config.start) ? config.start : ''
                this.end = parse(config.end) ? config.end : ''
                // Livewire mode: follow the two properties, also when the server changes them.
                if (config.wireStart && this.$wire) {
                    this.start = this.$wire.get(config.wireStart) || ''
                    this.end = this.$wire.get(config.wireEnd) || ''
                    this.$wire.$watch(config.wireStart, (v) => { if (! this.picking) this.start = parse(v) ? v : '' })
                    this.$wire.$watch(config.wireEnd, (v) => { if (! this.picking) this.end = parse(v) ? v : '' })
                }
            }
            this.$watch('value', (v) => {
                if (v !== '' && ! parse(v)) this.value = ''
            })
        },

        isDisabled(date) {
            return (this.min !== null && date < this.min)
                || (this.max !== null && date > this.max)
                || (this.weekends && weekday(date) >= 5)
                || this.blocked.includes(date)
        },

        formatDate(text, style = 'medium') {
            let date = parse(text)
            return date ? new Intl.DateTimeFormat(locale, { dateStyle: style, timeZone: 'UTC' }).format(date) : ''
        },

        get display() {
            if (! this.range) return this.formatDate(this.value)
            if (this.start && this.end) return this.formatDate(this.start) + ' – ' + this.formatDate(this.end)
            if (this.start) return config.text.from.replace(':date', this.formatDate(this.start))
            if (this.end) return config.text.until.replace(':date', this.formatDate(this.end))
            return ''
        },

        get monthLabel() {
            return new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(parse(this.month))
        },

        get weekdays() {
            // 2024-01-01 was a Monday.
            return [0, 1, 2, 3, 4, 5, 6].map((i) => {
                let date = new Date(Date.UTC(2024, 0, 1 + i))
                return {
                    short: new Intl.DateTimeFormat(locale, { weekday: 'short', timeZone: 'UTC' }).format(date),
                    long: new Intl.DateTimeFormat(locale, { weekday: 'long', timeZone: 'UTC' }).format(date),
                }
            })
        },

        // Six rows of seven days, Monday first, around the shown month.
        get weeks() {
            let first = this.month.slice(0, 8) + '01'
            let day = addDays(first, -weekday(first))
            let weeks = []
            for (let w = 0; w < 6; w++) {
                let week = []
                for (let d = 0; d < 7; d++) {
                    week.push(day)
                    day = addDays(day, 1)
                }
                weeks.push(week)
            }
            return weeks
        },

        dayNumber(date) {
            return new Intl.DateTimeFormat(locale, { day: 'numeric', timeZone: 'UTC' }).format(parse(date))
        },

        inMonth(date) {
            return date.slice(0, 7) === this.month.slice(0, 7)
        },

        isChosen(date) {
            return this.range ? date === this.start || date === this.end : date === this.value
        },

        inRange(date) {
            return this.range && this.start && this.end && date > this.start && date < this.end
        },

        clamp(date) {
            if (this.min !== null && date < this.min) return this.min
            if (this.max !== null && date > this.max) return this.max
            return date
        },

        toggle() {
            this.open ? this.close(true) : this.show()
        },

        show() {
            if (dom.trigger.disabled) return
            let base = this.range ? (this.start || this.end) : this.value
            this.focused = this.clamp(base || this.today)
            this.month = this.focused
            this.picking = false
            this.open = true
            this.$nextTick(() => {
                unfloat = float(dom.trigger, dom.panel)
                this.focusDay()
            })
        },

        close(focusTrigger = false) {
            if (! this.open) return
            this.open = false
            unfloat()
            if (this.range && this.picking) {
                this.picking = false
                this.commitRange()
            }
            if (focusTrigger) this.$nextTick(() => dom.trigger.focus())
        },

        onFocusOut(event) {
            if (this.open && ! dom.root.contains(event.relatedTarget)) this.close(false)
        },

        focusDay() {
            let el = dom.grid && dom.grid.querySelector('[data-date="' + this.focused + '"]')
            if (el) el.focus()
        },

        moveTo(date) {
            this.focused = this.clamp(date)
            this.month = this.focused
            this.$nextTick(() => this.focusDay())
        },

        shiftMonth(months) {
            this.month = addMonths(this.month.slice(0, 8) + '01', months)
            this.focused = this.clamp(addMonths(this.focused, months))
            if (! this.inMonth(this.focused)) this.focused = this.clamp(this.month)
        },

        onGridKey(event) {
            let keys = {
                ArrowLeft: () => this.moveTo(addDays(this.focused, -1)),
                ArrowRight: () => this.moveTo(addDays(this.focused, 1)),
                ArrowUp: () => this.moveTo(addDays(this.focused, -7)),
                ArrowDown: () => this.moveTo(addDays(this.focused, 7)),
                Home: () => this.moveTo(addDays(this.focused, -weekday(this.focused))),
                End: () => this.moveTo(addDays(this.focused, 6 - weekday(this.focused))),
                PageUp: () => this.moveTo(addMonths(this.focused, event.shiftKey ? -12 : -1)),
                PageDown: () => this.moveTo(addMonths(this.focused, event.shiftKey ? 12 : 1)),
                Enter: () => this.pick(this.focused),
                ' ': () => this.pick(this.focused),
            }
            if (keys[event.key]) {
                event.preventDefault()
                keys[event.key]()
            }
        },

        pick(date) {
            if (this.isDisabled(date)) return
            this.focused = date
            if (! this.range) {
                this.value = date
                this.close(true)
                return
            }
            if (! this.picking) {
                this.start = date
                this.end = ''
                this.picking = true
                return
            }
            if (date < this.start) {
                this.end = this.start
                this.start = date
            } else {
                this.end = date
            }
            this.picking = false
            this.commitRange()
            this.close(true)
        },

        clearValue() {
            if (this.range) {
                this.start = ''
                this.end = ''
                this.picking = false
                this.commitRange()
            } else {
                this.value = ''
            }
            this.close(true)
        },

        pickToday() {
            this.moveTo(this.today)
        },

        // Livewire mode sets both properties and sends one request.
        commitRange() {
            if (config.wireStart && this.$wire) {
                this.$wire.$set(config.wireStart, this.start, false)
                this.$wire.$set(config.wireEnd, this.end, !! config.live)
            }
        },
    }
}

/* ------------------------------------------------------------------------------------------------------ */
/* One-time code: one real input (autofill from SMS, paste and delete stay native) under six boxes.      */
/* ------------------------------------------------------------------------------------------------------ */

export function uiOtp(config) {
    return {
        value: '',
        focused: false,
        length: config.length || 6,

        init() {
            this.value = this.clean(config.value)
            this.$watch('value', (v) => {
                let cleaned = this.clean(v)
                if (cleaned !== v) this.value = cleaned
                if (cleaned.length === this.length) this.$dispatch('otp-complete', { value: cleaned })
            })
        },

        clean(text) {
            return String(text ?? '').replace(/\D/g, '').slice(0, this.length)
        },

        // A pasted code replaces the value, cleaned first: the browser would otherwise cut "123 456" or
        // "12-34-56" at maxlength before Alpine sees it.
        paste(event) {
            let text = (event.clipboardData || window.clipboardData)?.getData('text') ?? ''
            this.value = this.clean(text)
            this.$nextTick(() => this.keepCaretAtEnd())
        },

        // The caret always sits after the last digit, like the boxes show.
        keepCaretAtEnd() {
            let input = this.$refs.input
            let end = input.value.length
            if (input.selectionStart !== end || input.selectionEnd !== end) input.setSelectionRange(end, end)
        },

        get activeIndex() {
            return this.focused ? Math.min(this.value.length, this.length - 1) : -1
        },

        char(index) {
            return this.value[index] ?? ''
        },
    }
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('uiSelect', uiSelect)
    window.Alpine.data('uiDatePicker', uiDatePicker)
    window.Alpine.data('uiOtp', uiOtp)
})

/*
 * Phase 15e: where the focus goes back when a dialog closes. A dialog's button often starts a Livewire request
 * first (openCreate, openEdit): while it runs the button is disabled, and a disabled button loses the focus, so
 * by the time the dialog opens the focus is on <body> and the focus trap would "return" it there. Instead the
 * last control focused outside any dialog is remembered here, and the dialog gives the focus back to it, or, for
 * an item of a menu that is closed by then, to the menu's button. A control replaced by a Livewire update is
 * found again by its id or the element it controls.
 */
document.addEventListener('focusin', (event) => {
    if (! event.target.closest?.('[role=dialog][aria-modal=true]')) window.uiLastFocus = event.target
})

window.uiRestoreFocus = (element) => {
    let target = element
    if (target && ! target.isConnected) {
        target = (target.id && document.getElementById(target.id)) || null
    }
    let menu = target?.closest?.('[role=menu]')
    if (menu) target = document.querySelector(`[aria-controls="${menu.id}"]`)
    if (! target || ! target.isConnected) return
    let attempts = 0
    let tryFocus = () => {
        // Still disabled while its own request finishes: try again shortly.
        if (target.disabled && attempts++ < 20) return setTimeout(tryFocus, 50)
        target.focus({ preventScroll: true })
    }
    tryFocus()
}
