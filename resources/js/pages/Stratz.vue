<template>
    <div class="min-h-screen bg-slate-950 text-slate-100">
        <div class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-8 md:px-8">
            <header class="rounded-3xl border border-slate-800 bg-slate-900/80 p-6 shadow-2xl shadow-slate-950/40">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div class="space-y-2">
                        <p class="text-xs font-semibold tracking-[0.35em] text-rose-300/80 uppercase">
                            STRATZ ROSH Workspace
                        </p>
                        <h1 class="text-3xl font-semibold tracking-tight text-white">
                            ROSH по MatchID и по героям
                        </h1>
                        <p class="max-w-3xl text-sm leading-6 text-slate-300">
                            Используйте MatchID, если хотите повторить расчёт по реальному матчу, или соберите свой
                            live-драфт по героям. Оба режима возвращают одинаковый ROSH-результат и умеют писать odds в
                            Google Sheets.
                        </p>
                    </div>

                    <div class="rounded-2xl border border-slate-800 bg-slate-950/70 px-4 py-3 text-xs leading-6 text-slate-400">
                        <div>STRATZ token: <code>STRATZ_TOKEN</code></div>
                        <div>Hero mode bracket: <span class="font-semibold text-slate-200">Titan / Immortal</span></div>
                        <div>Hero mode date: <span class="font-semibold text-slate-200">current timestamp</span></div>
                    </div>
                </div>
            </header>

            <section class="rounded-3xl border border-slate-800 bg-slate-900/60 p-3 shadow-xl shadow-slate-950/30">
                <div class="grid gap-3 md:grid-cols-2">
                    <button
                        v-for="tab in tabs"
                        :key="tab.id"
                        type="button"
                        class="rounded-2xl border px-4 py-4 text-left transition duration-200"
                        :class="
                            activeTab === tab.id
                                ? tab.activeClasses
                                : 'border-slate-800 bg-slate-950/70 text-slate-300 hover:border-slate-700 hover:bg-slate-900'
                        "
                        @click="activeTab = tab.id"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-base font-semibold">{{ tab.label }}</span>
                            <span
                                class="rounded-full border px-2 py-0.5 text-[10px] font-semibold tracking-[0.25em] uppercase"
                                :class="
                                    activeTab === tab.id
                                        ? tab.badgeClasses
                                        : 'border-slate-700 text-slate-500'
                                "
                            >
                                {{ tab.shortLabel }}
                            </span>
                        </div>
                        <p
                            class="mt-2 text-sm leading-6"
                            :class="activeTab === tab.id ? 'text-slate-100/80' : 'text-slate-400'"
                        >
                            {{ tab.description }}
                        </p>
                    </button>
                </div>
            </section>

            <section
                v-if="activeTab === 'matchId'"
                class="rounded-3xl border border-rose-500/20 bg-slate-900/60 p-6 shadow-xl shadow-slate-950/30"
            >
                <div class="mb-5 flex flex-col gap-2">
                    <p class="text-xs font-semibold tracking-[0.3em] text-rose-300 uppercase">
                        ROSH
                    </p>
                    <h2 class="text-xl font-semibold text-white">По MatchID</h2>
                    <p class="max-w-3xl text-sm leading-6 text-slate-300">
                        Введите Match ID, и backend сам подтянет команды, пики, bracket и дату матча, после чего
                        выполнит тот же ROSH-расчёт, что уже есть в проекте.
                    </p>
                </div>

                <form class="flex max-w-xl flex-col gap-4" @submit.prevent="submitRoshByMatchId">
                    <label class="flex flex-col gap-2 text-sm text-slate-200">
                        Match ID
                        <input
                            v-model="matchForm.matchId"
                            type="number"
                            min="1"
                            required
                            class="rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-slate-100 outline-none ring-0 transition placeholder:text-slate-500 focus:border-rose-400"
                        />
                    </label>

                    <div class="rounded-2xl border border-slate-800 bg-slate-950/60 p-4 text-xs leading-6 text-slate-400">
                        Summary и minute-by-minute table будут построены по тем же данным, что и текущий ROSH pipeline:
                        match context, hero stats by time и synergy.
                    </div>

                    <button
                        type="submit"
                        class="w-full rounded-xl bg-rose-500 px-4 py-3 text-sm font-semibold text-slate-950 transition hover:bg-rose-400 disabled:cursor-not-allowed disabled:opacity-60 md:w-auto"
                        :disabled="isLoading('rosh-match')"
                    >
                        {{ isLoading('rosh-match') ? 'Считаем...' : 'Рассчитать' }}
                    </button>
                </form>
            </section>

            <section
                v-else
                class="rounded-3xl border border-cyan-500/20 bg-slate-900/60 p-6 shadow-xl shadow-slate-950/30"
            >
                <div class="mb-5 flex flex-col gap-2">
                    <p class="text-xs font-semibold tracking-[0.3em] text-cyan-300 uppercase">
                        ROSH
                    </p>
                    <h2 class="text-xl font-semibold text-white">По Героям</h2>
                    <p class="max-w-3xl text-sm leading-6 text-slate-300">
                        Соберите live-драфт вручную: названия команд и по 5 героев на каждую сторону с ролями Керри,
                        Мидер, Оффлейнер, Четвёрка и Пятёрка. После расчёта результат также уйдёт в Google Sheets, а в
                        колонку Match ID будет записано <code>LIVE</code>.
                    </p>
                </div>

                <form class="space-y-6" @submit.prevent="submitRoshByHeroes">
                    <div class="grid gap-4 xl:grid-cols-2">
                        <section class="rounded-2xl border border-emerald-500/20 bg-slate-950/70 p-5">
                            <div class="mb-4 space-y-1">
                                <p class="text-xs font-semibold tracking-[0.3em] text-emerald-300 uppercase">
                                    Radiant
                                </p>
                            </div>

                            <div class="space-y-4">
                                <label class="flex flex-col gap-2 text-sm text-slate-200">
                                    Название команды Radiant
                                    <input
                                        v-model="heroForm.radiantTeam"
                                        type="text"
                                        required
                                        class="rounded-xl border border-slate-700 bg-slate-900 px-4 py-3 text-sm text-slate-100 outline-none transition placeholder:text-slate-500 focus:border-emerald-400"
                                        placeholder="Team Liquid"
                                    />
                                </label>

                                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-1">
                                    <div
                                        v-for="role in roles"
                                        :key="`radiant-${role.position}`"
                                        class="space-y-2 text-sm text-slate-200"
                                    >
                                        <div class="flex items-center justify-between gap-3">
                                            <span class="font-medium text-emerald-200">{{ role.label }}</span>
                                        </div>

                                        <div class="relative" data-hero-picker>
                                            <div
                                                class="flex items-center gap-3 rounded-2xl border px-3 py-2 transition"
                                                :class="
                                                    isHeroPickerOpen('radiant', role.position - 1)
                                                        ? 'border-emerald-400/70 bg-emerald-500/8 shadow-[0_0_0_1px_rgba(52,211,153,0.18)]'
                                                        : 'border-slate-700 bg-slate-900/90 hover:border-slate-600'
                                                "
                                            >
                                                <div
                                                    class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-xl border"
                                                    :class="
                                                        selectedHeroFor('radiant', role.position - 1)
                                                            ? 'border-emerald-400/30 bg-slate-950'
                                                            : 'border-slate-700 bg-slate-950 text-emerald-200'
                                                    "
                                                >
                                                    <img
                                                        v-if="selectedHeroFor('radiant', role.position - 1)"
                                                        :src="selectedHeroFor('radiant', role.position - 1)?.image"
                                                        :alt="selectedHeroFor('radiant', role.position - 1)?.title"
                                                        class="h-full w-full object-cover"
                                                    />
                                                    <span v-else class="text-xs font-semibold uppercase">
                                                        {{ role.position }}
                                                    </span>
                                                </div>

                                                <div class="min-w-0 flex-1">
                                                    <input
                                                        :value="getHeroValue('radiant', role.position - 1)"
                                                        :data-hero-input="heroPickerKey('radiant', role.position - 1)"
                                                        type="text"
                                                        autocomplete="off"
                                                        required
                                                        class="w-full bg-transparent text-sm text-slate-100 outline-none placeholder:text-slate-500"
                                                        :placeholder="`Выберите героя для ${role.label}`"
                                                        @focus="openHeroPicker('radiant', role.position - 1)"
                                                        @click="openHeroPicker('radiant', role.position - 1)"
                                                        @input="handleHeroInput('radiant', role.position - 1, $event)"
                                                        @keydown.down.prevent="handleHeroArrowDown('radiant', role.position - 1)"
                                                        @keydown.up.prevent="handleHeroArrowUp('radiant', role.position - 1)"
                                                        @keydown.enter.prevent="selectActiveHeroMatch('radiant', role.position - 1)"
                                                        @keydown.escape="closeHeroPicker"
                                                    />
                                                </div>

                                                <button
                                                    v-if="getHeroValue('radiant', role.position - 1)"
                                                    type="button"
                                                    tabindex="-1"
                                                    class="rounded-lg border border-slate-700 px-2 py-1 text-xs font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white"
                                                    @click="clearHeroSelection('radiant', role.position - 1)"
                                                >
                                                    ×
                                                </button>
                                            </div>

                                            <div
                                                v-if="isHeroPickerOpen('radiant', role.position - 1)"
                                                class="absolute inset-x-0 top-full z-30 mt-2 overflow-hidden rounded-2xl border border-slate-700 bg-slate-950/98 shadow-2xl shadow-slate-950/70"
                                            >
                                                <div class="border-b border-slate-800 px-3 py-2 text-[11px] font-semibold tracking-[0.22em] text-slate-400 uppercase">
                                                    Первые 5 героев
                                                </div>

                                                <div class="max-h-72 overflow-y-auto p-2">
                                                    <button
                                                        v-for="(hero, optionIndex) in getHeroMatches('radiant', role.position - 1)"
                                                        :key="hero.id"
                                                        type="button"
                                                        :data-hero-option="`${heroPickerKey('radiant', role.position - 1)}-${optionIndex}`"
                                                        class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left transition"
                                                        :class="
                                                            getActiveHeroMatchIndex('radiant', role.position - 1) === optionIndex
                                                                ? 'bg-emerald-500/15 text-white ring-1 ring-emerald-400/40'
                                                                : selectedHeroFor('radiant', role.position - 1)?.id === hero.id
                                                                  ? 'bg-emerald-500/10 text-white'
                                                                  : 'text-slate-200 hover:bg-slate-900 hover:text-white'
                                                        "
                                                        @mouseenter="setActiveHeroMatchIndex('radiant', role.position - 1, optionIndex)"
                                                        @focus="setActiveHeroMatchIndex('radiant', role.position - 1, optionIndex)"
                                                        @keydown.down.prevent="handleHeroOptionArrowDown('radiant', role.position - 1)"
                                                        @keydown.up.prevent="handleHeroOptionArrowUp('radiant', role.position - 1)"
                                                        @keydown.enter.prevent="selectActiveHeroMatch('radiant', role.position - 1)"
                                                        @mousedown.prevent="selectHero('radiant', role.position - 1, hero)"
                                                    >
                                                        <img
                                                            :src="hero.image"
                                                            :alt="hero.title"
                                                            class="h-9 w-9 shrink-0 rounded-lg border border-slate-700 object-cover"
                                                        />
                                                        <div class="min-w-0 flex-1">
                                                            <div class="truncate font-semibold">
                                                                {{ hero.title }}
                                                            </div>
                                                        </div>
                                                    </button>

                                                    <div
                                                        v-if="getHeroMatches('radiant', role.position - 1).length === 0"
                                                        class="px-3 py-4 text-sm text-slate-500"
                                                    >
                                                        Ничего не найдено. Попробуйте другую часть имени героя.
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section class="rounded-2xl border border-rose-500/20 bg-slate-950/70 p-5">
                            <div class="mb-4 space-y-1">
                                <p class="text-xs font-semibold tracking-[0.3em] text-rose-300 uppercase">
                                    Dire
                                </p>
                            </div>

                            <div class="space-y-4">
                                <label class="flex flex-col gap-2 text-sm text-slate-200">
                                    Название команды Dire
                                    <input
                                        v-model="heroForm.direTeam"
                                        type="text"
                                        required
                                        class="rounded-xl border border-slate-700 bg-slate-900 px-4 py-3 text-sm text-slate-100 outline-none transition placeholder:text-slate-500 focus:border-rose-400"
                                        placeholder="GamerLegion"
                                    />
                                </label>

                                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-1">
                                    <div
                                        v-for="role in roles"
                                        :key="`dire-${role.position}`"
                                        class="space-y-2 text-sm text-slate-200"
                                    >
                                        <div class="flex items-center justify-between gap-3">
                                            <span class="font-medium text-rose-200">{{ role.label }}</span>
                                        </div>

                                        <div class="relative" data-hero-picker>
                                            <div
                                                class="flex items-center gap-3 rounded-2xl border px-3 py-2 transition"
                                                :class="
                                                    isHeroPickerOpen('dire', role.position - 1)
                                                        ? 'border-rose-400/70 bg-rose-500/8 shadow-[0_0_0_1px_rgba(251,113,133,0.18)]'
                                                        : 'border-slate-700 bg-slate-900/90 hover:border-slate-600'
                                                "
                                            >
                                                <div
                                                    class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-xl border"
                                                    :class="
                                                        selectedHeroFor('dire', role.position - 1)
                                                            ? 'border-rose-400/30 bg-slate-950'
                                                            : 'border-slate-700 bg-slate-950 text-rose-200'
                                                    "
                                                >
                                                    <img
                                                        v-if="selectedHeroFor('dire', role.position - 1)"
                                                        :src="selectedHeroFor('dire', role.position - 1)?.image"
                                                        :alt="selectedHeroFor('dire', role.position - 1)?.title"
                                                        class="h-full w-full object-cover"
                                                    />
                                                    <span v-else class="text-xs font-semibold uppercase">
                                                        {{ role.position }}
                                                    </span>
                                                </div>

                                                <div class="min-w-0 flex-1">
                                                    <input
                                                        :value="getHeroValue('dire', role.position - 1)"
                                                        :data-hero-input="heroPickerKey('dire', role.position - 1)"
                                                        type="text"
                                                        autocomplete="off"
                                                        required
                                                        class="w-full bg-transparent text-sm text-slate-100 outline-none placeholder:text-slate-500"
                                                        :placeholder="`Выберите героя для ${role.label}`"
                                                        @focus="openHeroPicker('dire', role.position - 1)"
                                                        @click="openHeroPicker('dire', role.position - 1)"
                                                        @input="handleHeroInput('dire', role.position - 1, $event)"
                                                        @keydown.down.prevent="handleHeroArrowDown('dire', role.position - 1)"
                                                        @keydown.up.prevent="handleHeroArrowUp('dire', role.position - 1)"
                                                        @keydown.enter.prevent="selectActiveHeroMatch('dire', role.position - 1)"
                                                        @keydown.escape="closeHeroPicker"
                                                    />
                                                </div>

                                                <button
                                                    v-if="getHeroValue('dire', role.position - 1)"
                                                    type="button"
                                                    tabindex="-1"
                                                    class="rounded-lg border border-slate-700 px-2 py-1 text-xs font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white"
                                                    @click="clearHeroSelection('dire', role.position - 1)"
                                                >
                                                    ×
                                                </button>
                                            </div>

                                            <div
                                                v-if="isHeroPickerOpen('dire', role.position - 1)"
                                                class="absolute inset-x-0 top-full z-30 mt-2 overflow-hidden rounded-2xl border border-slate-700 bg-slate-950/98 shadow-2xl shadow-slate-950/70"
                                            >
                                                <div class="border-b border-slate-800 px-3 py-2 text-[11px] font-semibold tracking-[0.22em] text-slate-400 uppercase">
                                                    Первые 5 героев
                                                </div>

                                                <div class="max-h-72 overflow-y-auto p-2">
                                                    <button
                                                        v-for="(hero, optionIndex) in getHeroMatches('dire', role.position - 1)"
                                                        :key="hero.id"
                                                        type="button"
                                                        :data-hero-option="`${heroPickerKey('dire', role.position - 1)}-${optionIndex}`"
                                                        class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left transition"
                                                        :class="
                                                            getActiveHeroMatchIndex('dire', role.position - 1) === optionIndex
                                                                ? 'bg-rose-500/15 text-white ring-1 ring-rose-400/40'
                                                                : selectedHeroFor('dire', role.position - 1)?.id === hero.id
                                                                  ? 'bg-rose-500/10 text-white'
                                                                  : 'text-slate-200 hover:bg-slate-900 hover:text-white'
                                                        "
                                                        @mouseenter="setActiveHeroMatchIndex('dire', role.position - 1, optionIndex)"
                                                        @focus="setActiveHeroMatchIndex('dire', role.position - 1, optionIndex)"
                                                        @keydown.down.prevent="handleHeroOptionArrowDown('dire', role.position - 1)"
                                                        @keydown.up.prevent="handleHeroOptionArrowUp('dire', role.position - 1)"
                                                        @keydown.enter.prevent="selectActiveHeroMatch('dire', role.position - 1)"
                                                        @mousedown.prevent="selectHero('dire', role.position - 1, hero)"
                                                    >
                                                        <img
                                                            :src="hero.image"
                                                            :alt="hero.title"
                                                            class="h-9 w-9 shrink-0 rounded-lg border border-slate-700 object-cover"
                                                        />
                                                        <div class="min-w-0 flex-1">
                                                            <div class="truncate font-semibold">
                                                                {{ hero.title }}
                                                            </div>
                                                        </div>
                                                    </button>

                                                    <div
                                                        v-if="getHeroMatches('dire', role.position - 1).length === 0"
                                                        class="px-3 py-4 text-sm text-slate-500"
                                                    >
                                                        Ничего не найдено. Попробуйте другую часть имени героя.
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </section>
                    </div>

                    <div class="rounded-2xl border border-slate-800 bg-slate-950/60 p-4 text-xs leading-6 text-slate-400">
                        Итоговый winner для hero mode — это прогноз по последней точке ROSH minute table. В Google
                        Sheets всегда создаётся новая строка, а в Match ID записывается <code>LIVE</code>.
                    </div>

                    <button
                        type="submit"
                        class="w-full rounded-xl bg-cyan-400 px-4 py-3 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300 disabled:cursor-not-allowed disabled:opacity-60 md:w-auto"
                        :disabled="isLoading('rosh-heroes')"
                    >
                        {{ isLoading('rosh-heroes') ? 'Считаем...' : 'Рассчитать' }}
                    </button>
                </form>
            </section>

            <section
                v-if="errorMessage"
                class="rounded-2xl border border-rose-700 bg-rose-950/60 p-4 text-sm leading-6 text-rose-200"
            >
                {{ errorMessage }}
            </section>

            <section
                v-if="roshSummary"
                class="rounded-3xl border border-slate-800 bg-slate-900/60 p-6 shadow-xl shadow-slate-950/30"
            >
                <div class="mb-4 flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                    <div>
                        <p class="text-xs font-semibold tracking-[0.3em] text-slate-400 uppercase">
                            Result
                        </p>
                        <h2 class="text-xl font-semibold text-white">
                            ROSH summary
                        </h2>
                    </div>

                    <div
                        class="inline-flex w-fit rounded-full border border-slate-700 bg-slate-950/80 px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em]"
                        :class="roshSummary.winner === 'radiant' ? 'text-emerald-300' : 'text-rose-300'"
                    >
                        Winner: {{ roshSummary.winner }}
                    </div>
                </div>

                <div class="space-y-5">
                    <div class="overflow-auto rounded-2xl border border-slate-800">
                        <table class="w-full min-w-[1080px] text-sm">
                            <thead class="bg-slate-900/90 text-slate-200">
                                <tr>
                                    <th class="px-3 py-2 text-left">Match ID</th>
                                    <th class="px-3 py-2 text-left">Winner</th>
                                    <th class="px-3 py-2 text-left">Radiant</th>
                                    <th class="px-3 py-2 text-left">Dire</th>
                                    <th class="px-3 py-2 text-left">Radiant odds 1</th>
                                    <th class="px-3 py-2 text-left">Radiant odds 2</th>
                                    <th class="px-3 py-2 text-left">Dire odds 1</th>
                                    <th class="px-3 py-2 text-left">Dire odds 2</th>
                                    <th class="px-3 py-2 text-left">Bracket</th>
                                    <th class="px-3 py-2 text-left">Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="border-t border-slate-800 bg-slate-950/60">
                                    <td class="px-3 py-3 font-mono text-xs text-slate-300">
                                        {{ roshSummary.match_id }}
                                    </td>
                                    <td class="px-3 py-3 capitalize">
                                        {{ roshSummary.winner }}
                                    </td>
                                    <td class="px-3 py-3 text-emerald-300">
                                        {{ roshSummary.radiant_team }}
                                    </td>
                                    <td class="px-3 py-3 text-rose-300">
                                        {{ roshSummary.dire_team }}
                                    </td>
                                    <td class="px-3 py-3 text-emerald-300">
                                        {{ formatPercentValue(roshSummary.radiant_odds_1) }}
                                    </td>
                                    <td class="px-3 py-3 text-emerald-300">
                                        {{ formatPercentValue(roshSummary.radiant_odds_2) }}
                                    </td>
                                    <td class="px-3 py-3 text-rose-300">
                                        {{ formatPercentValue(roshSummary.dire_odds_1) }}
                                    </td>
                                    <td class="px-3 py-3 text-rose-300">
                                        {{ formatPercentValue(roshSummary.dire_odds_2) }}
                                    </td>
                                    <td class="px-3 py-3 text-slate-200">
                                        {{ roshSummary.bracket_basic }}
                                    </td>
                                    <td class="px-3 py-3 font-mono text-xs text-slate-300">
                                        {{ formatUnixDate(roshSummary.date_time) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div
                        v-if="roshGoogleSheets"
                        class="rounded-2xl border border-cyan-400/20 bg-slate-950/60 p-4"
                    >
                        <div class="mb-3">
                            <p class="text-xs font-semibold tracking-[0.3em] text-cyan-200 uppercase">
                                Sync
                            </p>
                            <h3 class="text-base font-semibold text-white">
                                Google Sheets write-back
                            </h3>
                            <p class="mt-1 text-xs leading-5 text-slate-400">
                                Sheet {{ roshGoogleSheets.sheet_title }}, row {{ roshGoogleSheets.row }} was synced after
                                the ROSH calculation.
                            </p>
                        </div>

                        <div class="overflow-auto rounded-xl border border-slate-800">
                            <table class="w-full min-w-[640px] text-sm">
                                <thead class="bg-slate-900/90 text-slate-200">
                                    <tr>
                                        <th class="px-3 py-2 text-left">Cell</th>
                                        <th class="px-3 py-2 text-left">Value</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="(value, cell) in roshGoogleSheets.cells"
                                        :key="cell"
                                        class="border-t border-slate-800 bg-slate-950/60 odd:bg-slate-950 even:bg-slate-900/40"
                                    >
                                        <td class="px-3 py-3 font-mono text-xs text-cyan-200">
                                            {{ cell }}
                                        </td>
                                        <td class="px-3 py-3 text-slate-100">
                                            {{ value || '—' }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div
                        v-if="roshMinuteTable.length > 0"
                        class="rounded-2xl border border-rose-400/20 bg-slate-950/60 p-4"
                    >
                        <div class="mb-3">
                            <p class="text-xs font-semibold tracking-[0.3em] text-rose-200 uppercase">
                                Table
                            </p>
                            <h3 class="text-base font-semibold text-white">
                                Minute-by-minute ROSH graph data
                            </h3>
                            <p class="mt-1 text-xs leading-5 text-slate-400">
                                Каждая строка — готовая точка для построения поминутного графика вероятности по ROSH.
                            </p>
                        </div>

                        <div class="overflow-auto rounded-xl border border-slate-800">
                            <table class="w-full min-w-[1200px] text-sm">
                                <thead class="bg-slate-900/90 text-slate-200">
                                    <tr>
                                        <th class="px-3 py-2 text-left">Minute</th>
                                        <th class="px-3 py-2 text-left">Window</th>
                                        <th class="px-3 py-2 text-left">Side</th>
                                        <th class="px-3 py-2 text-left">Radiant advantage</th>
                                        <th class="px-3 py-2 text-left">Dire advantage</th>
                                        <th class="px-3 py-2 text-left">Match %</th>
                                        <th class="px-3 py-2 text-left">Graph value</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="row in roshMinuteTable"
                                        :key="`rosh-minute-${row.minute}`"
                                        class="border-t border-slate-800 bg-slate-950/60 odd:bg-slate-950 even:bg-slate-900/40"
                                    >
                                        <td class="px-3 py-3 font-mono text-xs text-slate-300">
                                            {{ row.minute }}
                                        </td>
                                        <td class="px-3 py-3 font-mono text-xs text-slate-300">
                                            {{ formatMinuteWindow(row.time_start, row.time_end) }}
                                        </td>
                                        <td
                                            class="px-3 py-3 font-semibold"
                                            :class="
                                                row.advantage_side === 'radiant'
                                                    ? 'text-emerald-300'
                                                    : row.advantage_side === 'dire'
                                                      ? 'text-rose-300'
                                                      : 'text-slate-300'
                                            "
                                        >
                                            {{ formatAdvantageSide(row.advantage_side) }}
                                        </td>
                                        <td class="px-3 py-3 text-emerald-300">
                                            {{ formatPercentValue(row.radiant_advantage) }}
                                        </td>
                                        <td class="px-3 py-3 text-rose-300">
                                            {{ formatPercentValue(row.dire_advantage) }}
                                        </td>
                                        <td class="px-3 py-3 text-slate-200">
                                            {{ formatPercentValue(row.match_percentage) }}
                                        </td>
                                        <td
                                            class="px-3 py-3 font-semibold"
                                            :class="
                                                row.win_rate_graph > 0
                                                    ? 'text-emerald-300'
                                                    : row.win_rate_graph < 0
                                                      ? 'text-rose-300'
                                                      : 'text-slate-300'
                                            "
                                        >
                                            {{ formatSignedPercentValue(row.win_rate_graph) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-slate-800 bg-slate-950/60 p-4">
                        <div class="mb-3">
                            <p class="text-xs font-semibold tracking-[0.3em] text-slate-400 uppercase">
                                Request
                            </p>
                            <h3 class="text-base font-semibold text-white">
                                Raw ROSH request JSON
                            </h3>
                        </div>
                        <pre class="max-h-[50vh] overflow-auto rounded-lg border border-slate-800 bg-slate-950 p-4 text-xs text-slate-200">{{ formatJson(roshRequestData) }}</pre>
                    </div>

                    <div class="rounded-2xl border border-slate-800 bg-slate-950/60 p-4">
                        <div class="mb-3">
                            <p class="text-xs font-semibold tracking-[0.3em] text-slate-400 uppercase">
                                Raw
                            </p>
                            <h3 class="text-base font-semibold text-white">
                                Raw STRATZ ROSH response
                            </h3>
                        </div>
                        <pre class="max-h-[50vh] overflow-auto rounded-lg border border-slate-800 bg-slate-950 p-4 text-xs text-slate-200">{{ formatJson(roshRawData) }}</pre>
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';

import { rosh as roshAction, roshHeroes as roshHeroesAction } from '@/actions/App/Http/Controllers/StratzController';
import { getHeroSearchAliases } from '@/lib/hero-aliases';

type HeroOption = {
    id: number;
    name: string;
    title: string;
    image: string;
};

type SearchableHeroOption = {
    hero: HeroOption;
    searchableTitle: string;
    searchableName: string;
    searchableId: string;
    searchableAliases: string[];
};

type HeroSide = 'radiant' | 'dire';
type HeroPickerKey = `${HeroSide}-${number}`;
type StratzTab = 'matchId' | 'heroes';

type RoshFormattedResult = {
    match_id: number | string;
    winner: 'radiant' | 'dire';
    radiant_team: string;
    dire_team: string;
    bracket: string;
    bracket_basic: string;
    date_time: number;
    radiant_odds_1: number | null;
    radiant_odds_2: number | null;
    dire_odds_1: number | null;
    dire_odds_2: number | null;
};

type RoshMinuteTableRow = {
    minute: number;
    time_start: number;
    time_end: number;
    advantage_side: 'radiant' | 'dire' | 'even';
    advantage_percent: number;
    radiant_advantage: number;
    dire_advantage: number;
    match_percentage: number;
    win_rate_graph: number;
};

type RoshGoogleSheetsResult = {
    spreadsheet_id: string;
    sheet_title: string;
    row: number;
    cells: Record<string, string>;
};

type RoshResultPayload = {
    formatted?: RoshFormattedResult;
    minute_table?: RoshMinuteTableRow[];
    google_sheets?: RoshGoogleSheetsResult;
    request?: unknown;
    raw?: unknown;
};

type StratzResult = {
    type: string;
    data: unknown;
};

type RouteTarget = {
    url: string;
    method: string;
};

const props = defineProps<{
    heroes: HeroOption[];
}>();

const tabs: Array<{
    id: StratzTab;
    label: string;
    shortLabel: string;
    description: string;
    activeClasses: string;
    badgeClasses: string;
}> = [
    {
        id: 'heroes',
        label: 'По Героям',
        shortLabel: 'Heroes',
        description: 'Собрать драфт вручную, рассчитать ROSH и отправить LIVE-строку в Google Sheets.',
        activeClasses: 'border-cyan-400/50 bg-cyan-500/10 text-cyan-50',
        badgeClasses: 'border-cyan-300/40 bg-cyan-300/10 text-cyan-100',
    },
    {
        id: 'matchId',
        label: 'По MatchID',
        shortLabel: 'MatchID',
        description: 'Повторить ROSH-расчёт по существующему матчу STRATZ.',
        activeClasses: 'border-rose-400/50 bg-rose-500/10 text-rose-50',
        badgeClasses: 'border-rose-300/40 bg-rose-300/10 text-rose-100',
    },
];

const roles = [
    { position: 1, label: 'Керри' },
    { position: 2, label: 'Мидер' },
    { position: 3, label: 'Оффлейнер' },
    { position: 4, label: 'Четвёрка' },
    { position: 5, label: 'Пятёрка' },
] as const;

const activeTab = ref<StratzTab>('heroes');

const matchForm = reactive({
    matchId: '',
});

const heroForm = reactive({
    radiantTeam: '',
    direTeam: '',
    radiantHeroes: Array.from({ length: 5 }, () => ''),
    direHeroes: Array.from({ length: 5 }, () => ''),
});

const openHeroPickerKey = ref<HeroPickerKey | null>(null);
const activeHeroOptionIndex = ref(0);
const loadingAction = ref<string | null>(null);
const errorMessage = ref('');
const result = ref<StratzResult | null>(null);

const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';

const sortedHeroes = computed(() =>
    [...props.heroes].sort((left, right) => left.title.localeCompare(right.title, 'ru')),
);

const searchableHeroes = computed<SearchableHeroOption[]>(() =>
    sortedHeroes.value.map((hero) => ({
        hero,
        searchableTitle: normalizeHeroQuery(hero.title),
        searchableName: normalizeHeroQuery(hero.name),
        searchableId: String(hero.id),
        searchableAliases: getHeroSearchAliases(hero).map(normalizeHeroQuery),
    })),
);

const heroLookup = computed(() => {
    const lookup = new Map<string, HeroOption>();

    for (const hero of props.heroes) {
        for (const value of [hero.title, hero.name, String(hero.id)]) {
            lookup.set(normalizeHeroQuery(value), hero);
        }
    }

    return lookup;
});

const roshSummary = computed<RoshFormattedResult | null>(() => {
    if (result.value?.type !== 'rosh') {
        return null;
    }

    return (result.value.data as RoshResultPayload)?.formatted ?? null;
});

const roshRawData = computed(() => {
    if (result.value?.type !== 'rosh') {
        return null;
    }

    return (result.value.data as RoshResultPayload)?.raw ?? null;
});

const roshRequestData = computed(() => {
    if (result.value?.type !== 'rosh') {
        return null;
    }

    return (result.value.data as RoshResultPayload)?.request ?? null;
});

const roshMinuteTable = computed<RoshMinuteTableRow[]>(() => {
    if (result.value?.type !== 'rosh') {
        return [];
    }

    return (result.value.data as RoshResultPayload)?.minute_table ?? [];
});

const roshGoogleSheets = computed<RoshGoogleSheetsResult | null>(() => {
    if (result.value?.type !== 'rosh') {
        return null;
    }

    return (result.value.data as RoshResultPayload)?.google_sheets ?? null;
});

const isLoading = (action: string): boolean => loadingAction.value === action;

const jsonHeaders = () => ({
    'Content-Type': 'application/json',
    Accept: 'application/json',
    'X-CSRF-Token': csrfToken,
});

const normalizeHeroQuery = (value: string): string =>
    value
        .trim()
        .toLowerCase()
        .replace(/[^\p{L}\p{N}]+/gu, ' ')
        .replace(/\s+/g, ' ');

const heroPickerKey = (side: HeroSide, index: number): HeroPickerKey => `${side}-${index}` as HeroPickerKey;

const getHeroValue = (side: HeroSide, index: number): string =>
    side === 'radiant' ? heroForm.radiantHeroes[index] : heroForm.direHeroes[index];

const setHeroValue = (side: HeroSide, index: number, value: string): void => {
    if (side === 'radiant') {
        heroForm.radiantHeroes[index] = value;
        return;
    }

    heroForm.direHeroes[index] = value;
};

const openHeroPicker = (side: HeroSide, index: number): void => {
    openHeroPickerKey.value = heroPickerKey(side, index);
    activeHeroOptionIndex.value = Math.max(getDefaultActiveHeroMatchIndex(side, index), 0);
};

const closeHeroPicker = (): void => {
    openHeroPickerKey.value = null;
};

const isHeroPickerOpen = (side: HeroSide, index: number): boolean =>
    openHeroPickerKey.value === heroPickerKey(side, index);

const resolveHero = (value: string): HeroOption | null => {
    if (value.trim() === '') {
        return null;
    }

    return heroLookup.value.get(normalizeHeroQuery(value)) ?? null;
};

const selectedHeroFor = (side: HeroSide, index: number): HeroOption | null => resolveHero(getHeroValue(side, index));

const getOccupiedHeroIds = (side: HeroSide, index: number): Set<number> => {
    const occupiedHeroIds = new Set<number>();

    for (const [heroIndex, heroValue] of heroForm.radiantHeroes.entries()) {
        if (side === 'radiant' && heroIndex === index) {
            continue;
        }

        const hero = resolveHero(heroValue);

        if (hero) {
            occupiedHeroIds.add(hero.id);
        }
    }

    for (const [heroIndex, heroValue] of heroForm.direHeroes.entries()) {
        if (side === 'dire' && heroIndex === index) {
            continue;
        }

        const hero = resolveHero(heroValue);

        if (hero) {
            occupiedHeroIds.add(hero.id);
        }
    }

    return occupiedHeroIds;
};

const getHeroMatchScore = (hero: SearchableHeroOption, query: string): number | null => {
    if ([hero.searchableTitle, hero.searchableName, hero.searchableId].includes(query)) {
        return 0;
    }

    if (hero.searchableAliases.includes(query)) {
        return 1;
    }

    if (hero.searchableTitle.startsWith(query)) {
        return 2;
    }

    if (hero.searchableTitle.split(' ').some((word) => word.startsWith(query))) {
        return 3;
    }

    if (hero.searchableAliases.some((alias) => alias.startsWith(query))) {
        return 4;
    }

    if (hero.searchableTitle.includes(query)) {
        return 5;
    }

    if (hero.searchableName.startsWith(query)) {
        return 6;
    }

    if (hero.searchableAliases.some((alias) => alias.split(' ').some((word) => word.startsWith(query)))) {
        return 7;
    }

    if (hero.searchableName.includes(query)) {
        return 8;
    }

    if (hero.searchableAliases.some((alias) => alias.includes(query))) {
        return 9;
    }

    if (hero.searchableId.startsWith(query)) {
        return 10;
    }

    return null;
};

const getHeroMatches = (side: HeroSide, index: number): HeroOption[] => {
    const query = normalizeHeroQuery(getHeroValue(side, index));
    const occupiedHeroIds = getOccupiedHeroIds(side, index);

    if (query === '') {
        return sortedHeroes.value.filter((hero) => ! occupiedHeroIds.has(hero.id)).slice(0, 5);
    }

    return searchableHeroes.value
        .map((hero) => ({
            hero: hero.hero,
            score: getHeroMatchScore(hero, query),
        }))
        .filter((hero) => ! occupiedHeroIds.has(hero.hero.id))
        .filter((hero): hero is { hero: HeroOption; score: number } => hero.score !== null)
        .sort((left, right) => {
            if (left.score !== right.score) {
                return left.score - right.score;
            }

            return left.hero.title.localeCompare(right.hero.title, 'en');
        })
        .slice(0, 5)
        .map((hero) => hero.hero);
};

const getDefaultActiveHeroMatchIndex = (side: HeroSide, index: number): number => {
    const matches = getHeroMatches(side, index);
    const selectedHero = selectedHeroFor(side, index);

    if (matches.length === 0) {
        return -1;
    }

    if (! selectedHero) {
        return 0;
    }

    const selectedIndex = matches.findIndex((hero) => hero.id === selectedHero.id);

    return selectedIndex >= 0 ? selectedIndex : 0;
};

const getActiveHeroMatchIndex = (side: HeroSide, index: number): number => {
    const matches = getHeroMatches(side, index);

    if (matches.length === 0) {
        return -1;
    }

    return Math.min(activeHeroOptionIndex.value, matches.length - 1);
};

const setActiveHeroMatchIndex = (side: HeroSide, index: number, nextIndex: number): void => {
    const matches = getHeroMatches(side, index);

    if (matches.length === 0) {
        activeHeroOptionIndex.value = 0;
        return;
    }

    const normalizedIndex = ((nextIndex % matches.length) + matches.length) % matches.length;

    activeHeroOptionIndex.value = normalizedIndex;
};

const focusActiveHeroOption = (side: HeroSide, index: number): void => {
    const activeIndex = getActiveHeroMatchIndex(side, index);

    if (activeIndex < 0) {
        return;
    }

    requestAnimationFrame(() => {
        const option = document.querySelector<HTMLElement>(
            `[data-hero-option="${heroPickerKey(side, index)}-${activeIndex}"]`,
        );

        option?.focus();
    });
};

const focusNextHeroInput = (side: HeroSide, index: number): void => {
    requestAnimationFrame(() => {
        const currentKey = heroPickerKey(side, index);
        const inputs = Array.from(document.querySelectorAll<HTMLInputElement>('[data-hero-input]'));
        const currentInputIndex = inputs.findIndex((input) => input.dataset.heroInput === currentKey);

        if (currentInputIndex < 0) {
            return;
        }

        const nextInput = inputs[currentInputIndex + 1] ?? inputs[currentInputIndex];

        nextInput?.focus();
    });
};

const moveActiveHeroMatch = (side: HeroSide, index: number, direction: 1 | -1): void => {
    if (! isHeroPickerOpen(side, index)) {
        openHeroPicker(side, index);
        return;
    }

    setActiveHeroMatchIndex(side, index, getActiveHeroMatchIndex(side, index) + direction);
};

const selectHero = (side: HeroSide, index: number, hero: HeroOption): void => {
    setHeroValue(side, index, hero.title);
    closeHeroPicker();
    focusNextHeroInput(side, index);
};

const selectActiveHeroMatch = (side: HeroSide, index: number): void => {
    const matches = getHeroMatches(side, index);
    const activeIndex = getActiveHeroMatchIndex(side, index);
    const activeHero = activeIndex >= 0 ? matches[activeIndex] : matches[0];

    if (! activeHero) {
        return;
    }

    selectHero(side, index, activeHero);
};

const handleHeroArrowDown = (side: HeroSide, index: number): void => {
    moveActiveHeroMatch(side, index, 1);
};

const handleHeroArrowUp = (side: HeroSide, index: number): void => {
    moveActiveHeroMatch(side, index, -1);
};

const handleHeroOptionArrowDown = (side: HeroSide, index: number): void => {
    moveActiveHeroMatch(side, index, 1);
    focusActiveHeroOption(side, index);
};

const handleHeroOptionArrowUp = (side: HeroSide, index: number): void => {
    moveActiveHeroMatch(side, index, -1);
    focusActiveHeroOption(side, index);
};

const updateHeroSearch = (side: HeroSide, index: number, value: string): void => {
    setHeroValue(side, index, value);
    openHeroPicker(side, index);
};

const clearHeroSelection = (side: HeroSide, index: number): void => {
    setHeroValue(side, index, '');
    openHeroPicker(side, index);
};

const handleHeroInput = (side: HeroSide, index: number, event: Event): void => {
    const value = event.target instanceof HTMLInputElement ? event.target.value : '';

    updateHeroSearch(side, index, value);
};

const handleDocumentPointerDown = (event: PointerEvent): void => {
    const target = event.target;

    if (target instanceof Element && target.closest('[data-hero-picker]')) {
        return;
    }

    closeHeroPicker();
};

onMounted(() => {
    document.addEventListener('pointerdown', handleDocumentPointerDown);
});

onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', handleDocumentPointerDown);
});

const request = async (action: string, route: RouteTarget, payload: unknown): Promise<void> => {
    loadingAction.value = action;
    errorMessage.value = '';

    try {
        const response = await fetch(route.url, {
            method: route.method.toUpperCase(),
            headers: jsonHeaders(),
            credentials: 'same-origin',
            body: JSON.stringify(payload),
        });

        const contentType = response.headers.get('content-type') || '';
        const body = contentType.includes('application/json') ? await response.json() : await response.text();

        if (! response.ok) {
            const message =
                typeof body === 'object'
                    ? body.error || JSON.stringify(body)
                    : body;

            throw new Error(message || 'Ошибка запроса');
        }

        result.value = {
            type: body.type ?? '',
            data: body.data ?? body,
        };
    } catch (error) {
        errorMessage.value = error instanceof Error ? error.message : String(error);
        throw error;
    } finally {
        loadingAction.value = null;
    }
};

const buildHeroIds = (heroes: string[], side: 'Radiant' | 'Dire'): number[] => {
    return heroes.map((heroValue, index) => {
        const hero = resolveHero(heroValue);

        if (! hero) {
            throw new Error(`Выберите корректного героя для ${side} ${roles[index].label}.`);
        }

        return hero.id;
    });
};

const submitRoshByMatchId = async (): Promise<void> => {
    if (! matchForm.matchId) {
        errorMessage.value = 'Укажите Match ID для ROSH.';
        return;
    }

    await request('rosh-match', roshAction.post(), {
        match_id: Number(matchForm.matchId),
    });
};

const submitRoshByHeroes = async (): Promise<void> => {
    const radiantTeam = heroForm.radiantTeam.trim();
    const direTeam = heroForm.direTeam.trim();

    if (radiantTeam === '' || direTeam === '') {
        errorMessage.value = 'Укажите обе команды для hero-based ROSH.';
        return;
    }

    try {
        const radiantHeroes = buildHeroIds(heroForm.radiantHeroes, 'Radiant');
        const direHeroes = buildHeroIds(heroForm.direHeroes, 'Dire');
        const allHeroes = [...radiantHeroes, ...direHeroes];

        if (new Set(allHeroes).size !== allHeroes.length) {
            errorMessage.value = 'В одном драфте не должно быть повторяющихся героев.';
            return;
        }

        await request('rosh-heroes', roshHeroesAction.post(), {
            radiant_team: radiantTeam,
            dire_team: direTeam,
            radiant_heroes: radiantHeroes,
            dire_heroes: direHeroes,
        });
    } catch (error) {
        errorMessage.value = error instanceof Error ? error.message : String(error);
    }
};

const formatJson = (value: unknown): string => JSON.stringify(value, null, 2);

const formatUnixDate = (value: number): string => {
    if (! Number.isFinite(value)) {
        return '—';
    }

    return new Date(value * 1000).toLocaleString('ru-RU');
};

const formatPercentValue = (value: number | null | undefined): string => {
    if (typeof value !== 'number' || ! Number.isFinite(value)) {
        return '—';
    }

    return `${value.toFixed(1)}%`;
};

const formatSignedPercentValue = (value: number | null | undefined): string => {
    if (typeof value !== 'number' || ! Number.isFinite(value)) {
        return '—';
    }

    return `${value > 0 ? '+' : ''}${value.toFixed(1)}%`;
};

const formatMinuteWindow = (start: number, end: number): string => {
    const startLabel = `${String(start).padStart(2, '0')}:00`;
    const endLabel = `${String(end).padStart(2, '0')}:00`;

    return start === end ? startLabel : `${startLabel} - ${endLabel}`;
};

const formatAdvantageSide = (value: RoshMinuteTableRow['advantage_side']): string => {
    if (value === 'radiant') {
        return 'Radiant';
    }

    if (value === 'dire') {
        return 'Dire';
    }

    return 'Even';
};
</script>
