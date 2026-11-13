(() => {
    const selector = 'input[type="date"], input[type="time"], input[type="datetime-local"]';
    const dialog = document.createElement('dialog');
    if (typeof dialog.showModal !== 'function') {
        return;
    }

    dialog.id = 'escale-picker-dialog';
    dialog.className = 'escale-date-picker';
    dialog.setAttribute('aria-labelledby', 'escale-picker-title');
    dialog.innerHTML = `
        <form method="dialog">
            <h2 id="escale-picker-title"></h2>
            <div class="escale-picker-calendar">
                <div class="escale-picker-month">
                    <button type="button" data-month="-1" aria-label="Mois précédent">‹</button>
                    <strong aria-live="polite"></strong>
                    <button type="button" data-month="1" aria-label="Mois suivant">›</button>
                </div>
                <div class="escale-picker-week">
                    <abbr title="Lundi">Lu</abbr><abbr title="Mardi">Ma</abbr><abbr title="Mercredi">Me</abbr>
                    <abbr title="Jeudi">Je</abbr><abbr title="Vendredi">Ve</abbr><abbr title="Samedi">Sa</abbr>
                    <abbr title="Dimanche">Di</abbr>
                </div>
                <div class="escale-picker-days" role="group" aria-label="Jours du mois"></div>
            </div>
            <fieldset class="escale-picker-time">
                <legend>Heure sur 24 heures</legend>
                <div class="escale-picker-dial">
                    <div>
                        <button type="button" data-time="hours" data-amount="1" aria-label="Ajouter une heure">+</button>
                        <input type="number" inputmode="numeric" min="0" max="23" required aria-label="Heures" placeholder="HH">
                        <button type="button" data-time="hours" data-amount="-1" aria-label="Retirer une heure">−</button>
                    </div>
                    <span aria-hidden="true">:</span>
                    <div>
                        <button type="button" data-time="minutes" data-amount="1" aria-label="Augmenter les minutes">+</button>
                        <input type="number" inputmode="numeric" min="0" max="59" required aria-label="Minutes" placeholder="MM">
                        <button type="button" data-time="minutes" data-amount="-1" aria-label="Diminuer les minutes">−</button>
                    </div>
                </div>
            </fieldset>
            <p class="escale-picker-error" role="alert" hidden></p>
            <div class="escale-picker-actions">
                <button type="button" data-clear>Effacer</button>
                <button type="button" data-close>Annuler</button>
                <button type="submit" data-apply>Appliquer</button>
            </div>
        </form>`;
    document.body.append(dialog);

    const title = dialog.querySelector('h2');
    const calendar = dialog.querySelector('.escale-picker-calendar');
    const monthHeading = calendar.querySelector('strong');
    const [previousMonth, nextMonth] = calendar.querySelectorAll('[data-month]');
    const days = calendar.querySelector('.escale-picker-days');
    const time = dialog.querySelector('fieldset');
    const [hours, minutes] = time.querySelectorAll('input');
    const error = dialog.querySelector('[role="alert"]');
    const clear = dialog.querySelector('[data-clear]');
    const cancel = dialog.querySelector('[data-close]');
    const apply = dialog.querySelector('[data-apply]');
    const controls = new WeakMap();

    const dayFormat = new Intl.DateTimeFormat('fr-FR', {dateStyle: 'full', timeZone: 'UTC'});
    const monthFormat = new Intl.DateTimeFormat('fr-FR', {month: 'long', year: 'numeric', timeZone: 'UTC'});
    const shortFormat = new Intl.DateTimeFormat('fr-FR', {day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC'});
    const moroccoDay = new Intl.DateTimeFormat('sv-SE', {dateStyle: 'short', timeZone: 'Africa/Casablanca'});
    const dateOf = (value) => new Date(`${value}T12:00:00Z`);
    const isoDay = (date) => date.toISOString().slice(0, 10);
    const addDays = (date, amount) => {
        const next = new Date(date);
        next.setUTCDate(next.getUTCDate() + amount);
        return next;
    };
    const monthStart = (date, offset = 0) => {
        const next = new Date(date);
        next.setUTCDate(1);
        next.setUTCMonth(next.getUTCMonth() + offset);
        return next;
    };
    const pad = (value) => String(value).padStart(2, '0');
    const labelOf = (input) => input.labels[0]?.textContent.trim() || (input.type === 'time' ? 'Heure' : 'Date');
    const icon = (clock) => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true">${clock
        ? '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>'
        : '<rect x="3" y="4.5" width="18" height="16.5" rx="2"/><path d="M16 2.5v4M8 2.5v4M3 10h18"/>'}</svg>`;

    let field;
    let trigger;
    let selected;
    let month;
    let today;

    const sync = (input) => {
        const button = controls.get(input);
        const value = input.value;
        const date = value && input.type !== 'time' ? shortFormat.format(dateOf(value.split('T')[0])) : '';
        const hour = value && input.type !== 'date' ? value.split('T').at(-1).slice(0, 5) : '';
        const display = [date, hour].filter(Boolean).join(' · ');
        button.querySelector('span').textContent = display || {
            date: 'Choisir une date', time: 'Choisir une heure', 'datetime-local': 'Choisir une date et une heure',
        }[input.type];
        button.dataset.empty = String(!display);
        button.disabled = input.matches(':disabled') || input.readOnly;
        button.setAttribute('aria-label', `${labelOf(input)}${display ? ', ' + display : ''}`);
        button.setAttribute('aria-controls', dialog.id);
        for (const attribute of ['aria-invalid', 'aria-describedby']) {
            if (input.hasAttribute(attribute)) {
                button.setAttribute(attribute, input.getAttribute(attribute));
            } else {
                button.removeAttribute(attribute);
            }
        }
    };

    const minDay = () => field.min.slice(0, 10);
    const maxDay = () => field.max.slice(0, 10);
    const inRange = (value) => (!minDay() || value >= minDay()) && (!maxDay() || value <= maxDay());

    const renderMonth = (focusDay) => {
        monthHeading.textContent = monthFormat.format(month);
        previousMonth.disabled = Boolean(minDay() && isoDay(month) <= minDay().slice(0, 7) + '-01');
        nextMonth.disabled = Boolean(maxDay() && isoDay(monthStart(month, 1)) > maxDay());
        days.replaceChildren();
        const offset = (month.getUTCDay() + 6) % 7;
        for (let index = 0; index < offset; index++) {
            days.append(document.createElement('span'));
        }
        for (let date = month; date.getUTCMonth() === month.getUTCMonth(); date = addDays(date, 1)) {
            const value = isoDay(date);
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = date.getUTCDate();
            button.dataset.day = value;
            button.disabled = !inRange(value);
            button.tabIndex = -1;
            button.setAttribute('aria-label', dayFormat.format(date));
            button.setAttribute('aria-pressed', String(value === selected));
            if (value === today) button.setAttribute('aria-current', 'date');
            days.append(button);
        }
        const active = (focusDay && days.querySelector(`[data-day="${focusDay}"]:enabled`))
            || days.querySelector('[aria-pressed="true"]:enabled')
            || days.querySelector('[aria-current="date"]:enabled')
            || days.querySelector('button:enabled');
        if (active) active.tabIndex = 0;
        return active;
    };

    const report = (message = '') => {
        error.textContent = message;
        error.hidden = !message;
    };

    const commit = (value) => {
        const before = field.value;
        if (value) {
            const candidate = field.cloneNode();
            candidate.value = value;
            if (!candidate.value || !candidate.validity.valid) {
                report(field.type === 'date' ? 'Choisissez une date autorisée.' : 'Choisissez un horaire autorisé.');
                return;
            }
        }
        field.value = value;
        sync(field);
        dialog.close();
        if (before !== field.value) {
            field.dispatchEvent(new Event('input', {bubbles: true}));
            field.dispatchEvent(new Event('change', {bubbles: true}));
        }
    };

    const open = (input, button) => {
        field = input;
        trigger = button;
        today = moroccoDay.format();
        selected = field.type === 'time' ? '' : field.value.split('T')[0];
        const hour = field.type === 'date' ? '' : field.value.split('T').at(-1);
        hours.value = hour.slice(0, 2);
        minutes.value = hour.slice(3, 5);
        const step = Number(field.step);
        const stepMinutes = step && step % 60 === 0 && 60 % (step / 60) === 0 ? step / 60 : 1;
        minutes.dataset.increment = String(stepMinutes);
        calendar.hidden = field.type === 'time';
        time.hidden = field.type === 'date';
        time.disabled = time.hidden;
        apply.hidden = time.hidden;
        clear.hidden = field.required || !field.value;
        title.textContent = field.type === 'date' && field.required ? 'Quand partez-vous ?' : labelOf(field);
        report();
        let active;
        if (!calendar.hidden) {
            const start = selected || today;
            month = monthStart(dateOf(inRange(start) ? start : (start < minDay() ? minDay() : maxDay())));
            active = renderMonth();
        }
        button.setAttribute('aria-expanded', 'true');
        dialog.showModal();
        (time.hidden ? active || cancel : hours).focus();
    };

    time.addEventListener('click', (event) => {
        const button = event.target.closest('[data-time]');
        if (!button) return;
        const input = button.dataset.time === 'hours' ? hours : minutes;
        const cycle = Number(input.max) + 1;
        const increment = Number(input.dataset.increment || 1);
        input.value = pad((Number(input.value) + increment * Number(button.dataset.amount) + cycle) % cycle);
        const other = input === hours ? minutes : hours;
        if (!other.value) other.value = '00';
        report();
    });

    calendar.addEventListener('click', (event) => {
        const button = event.target.closest('button');
        if (!button) return;
        if (button.dataset.month) {
            month = monthStart(month, Number(button.dataset.month));
            const active = renderMonth();
            if (button.disabled) active?.focus();
            return;
        }
        selected = button.dataset.day;
        report();
        if (field.type === 'date') {
            commit(selected);
        } else {
            renderMonth(selected)?.focus();
        }
    });

    days.addEventListener('keydown', (event) => {
        const amount = {ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7}[event.key];
        if (amount === undefined || !event.target.dataset.day) return;
        event.preventDefault();
        const date = addDays(dateOf(event.target.dataset.day), amount);
        const value = isoDay(date);
        if (!inRange(value)) return;
        month = monthStart(date);
        renderMonth(value)?.focus();
    });

    dialog.querySelector('form').addEventListener('submit', (event) => {
        event.preventDefault();
        if (calendar.hidden || selected) {
            const value = `${pad(hours.value)}:${pad(minutes.value)}`;
            commit(calendar.hidden ? value : `${selected}T${value}`);
        } else {
            report('Choisissez un jour.');
            days.querySelector('[tabindex="0"]')?.focus();
        }
    });
    clear.addEventListener('click', () => commit(''));
    cancel.addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => {
        trigger.setAttribute('aria-expanded', 'false');
        if (trigger.isConnected) trigger.focus();
        else if (field.id) controls.get(document.getElementById(field.id))?.focus();
    });

    const enhance = (input) => {
        if (controls.has(input)) return;
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'escale-picker-trigger';
        button.innerHTML = `<span></span>${icon(input.type === 'time')}`;
        button.setAttribute('aria-haspopup', 'dialog');
        button.setAttribute('aria-expanded', 'false');
        input.after(button);
        controls.set(input, button);
        input.classList.add('escale-picker-native');
        input.tabIndex = -1;
        input.setAttribute('aria-hidden', 'true');
        input.addEventListener('focus', () => button.focus());
        input.addEventListener('invalid', (event) => {
            event.preventDefault();
            button.setAttribute('aria-invalid', 'true');
            const firstInvalid = input.form?.querySelector('input:invalid, select:invalid, textarea:invalid');
            if (!firstInvalid || firstInvalid === input) button.focus();
        });
        input.addEventListener('change', () => sync(input));
        button.addEventListener('click', () => open(input, button));
        sync(input);
    };

    const scan = (node) => {
        if (!(node instanceof Element)) return;
        if (node.matches(selector)) enhance(node);
        node.querySelectorAll(selector).forEach(enhance);
    };
    window.addEventListener('pageshow', () => {
        document.querySelectorAll(selector).forEach((input) => {
            if (controls.has(input)) sync(input);
        });
    });
    document.addEventListener('reset', (event) => {
        requestAnimationFrame(() => event.target.querySelectorAll(selector).forEach((input) => {
            if (controls.has(input)) sync(input);
        }));
    });
    const initialize = () => {
        scan(document.body);
        new MutationObserver((entries) => {
            for (const entry of entries) {
                if (entry.type === 'childList') {
                    entry.addedNodes.forEach(scan);
                } else if (controls.has(entry.target)) {
                    sync(entry.target);
                } else if (entry.attributeName === 'hidden' && !entry.target.hidden) {
                    entry.target.querySelectorAll(selector).forEach((input) => {
                        if (controls.has(input)) sync(input);
                    });
                }
            }
        }).observe(document.body, {
            subtree: true, childList: true, attributes: true,
            attributeFilter: ['disabled', 'readonly', 'aria-invalid', 'aria-describedby', 'value', 'hidden'],
        });
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, {once: true});
    } else {
        initialize();
    }
})();
