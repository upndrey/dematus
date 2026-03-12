<template>
    <div class="min-h-screen bg-slate-950 text-slate-100">
        <div
            class="mx-auto flex w-full max-w-6xl flex-col gap-6 px-4 py-8 md:px-8"
        >
            <header
                class="rounded-2xl border border-slate-800 bg-slate-900/70 p-6 shadow-2xl shadow-slate-950/40"
            >
                <div
                    class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between"
                >
                    <div class="space-y-2">
                        <p
                            class="text-xs font-semibold tracking-[0.35em] text-cyan-300/80 uppercase"
                        >
                            STRATZ Workspace
                        </p>
                        <h1 class="text-3xl font-semibold tracking-tight">
                            Инструменты для матчей, лиг и драфта
                        </h1>
                        <p class="max-w-3xl text-sm leading-6 text-slate-300">
                            Разделите работу по сценариям: итоговые draft-вычисления,
                            список игр лиги, данные по матчу и выгрузка
                            про-игроков.
                        </p>
                    </div>
                    <div
                        class="rounded-xl border border-slate-800 bg-slate-950/70 px-4 py-3 text-xs text-slate-400"
                    >
                        Токен берется из <code>STRATZ_TOKEN</code>
                    </div>
                </div>
            </header>

            <section
                class="rounded-2xl border border-slate-800 bg-slate-900/60 p-3 shadow-xl shadow-slate-950/30"
            >
                <div class="flex snap-x gap-3 overflow-x-auto pb-1">
                    <button
                        v-for="tab in tabs"
                        :key="tab.id"
                        type="button"
                        class="group min-w-[220px] snap-start rounded-xl border px-4 py-3 text-left transition duration-200"
                        :class="
                            activeTab === tab.id
                                ? tab.activeClasses
                                : 'border-slate-800 bg-slate-950/70 text-slate-300 hover:border-slate-700 hover:bg-slate-900'
                        "
                        @click="activeTab = tab.id"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm font-semibold">{{
                                tab.label
                            }}</span>
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
                            class="mt-2 text-xs leading-5"
                            :class="
                                activeTab === tab.id
                                    ? 'text-slate-100/80'
                                    : 'text-slate-400'
                            "
                        >
                            {{ tab.description }}
                        </p>
                    </button>
                </div>
            </section>

            <section
                v-if="activeTab === 'draft'"
                class="rounded-2xl border border-amber-500/20 bg-slate-900/60 p-5 shadow-xl shadow-slate-950/30"
            >
                <div class="mb-5 flex flex-col gap-2">
                    <p
                        class="text-xs font-semibold tracking-[0.3em] text-amber-300 uppercase"
                    >
                        Итоговые вычисления
                    </p>
                    <h2 class="text-xl font-semibold">Draft</h2>
                    <p class="max-w-3xl text-sm text-slate-300">
                        Введите только ID матча. Backend сам соберет payload для
                        STRATZ Plus Draft из пиков, банов, игроков, режима и
                        версии игры.
                    </p>
                </div>

                <form
                    class="flex flex-col gap-4 md:max-w-xl"
                    @submit.prevent="submitDraft"
                >
                    <label class="flex flex-col gap-2 text-sm">
                        Match ID
                        <input
                            v-model="draftForm.matchId"
                            type="number"
                            min="1"
                            required
                            class="rounded-md border border-slate-700 bg-slate-950 px-3 py-2"
                        />
                    </label>

                    <div
                        class="rounded-xl border border-slate-800 bg-slate-950/60 p-4 text-xs leading-6 text-slate-400"
                    >
                        Красивый вывод покажет winner и odds из массива
                        <code>winValues</code>: первый элемент как старт с
                        20-й минуты, а финальный элемент как последнюю доступную
                        точку массива.
                    </div>

                    <button
                        type="submit"
                        class="w-full rounded-md bg-amber-500 px-4 py-2 text-sm font-medium text-slate-950 hover:bg-amber-400 disabled:opacity-60 md:w-auto"
                        :disabled="isLoading('draft')"
                    >
                        {{
                            isLoading('draft')
                                ? 'Загрузка...'
                                : 'Выполнить draft по матчу'
                        }}
                    </button>
                </form>
            </section>

            <section
                v-else-if="activeTab === 'rosh'"
                class="rounded-2xl border border-rose-500/20 bg-slate-900/60 p-5 shadow-xl shadow-slate-950/30"
            >
                <div class="mb-5 flex flex-col gap-2">
                    <p
                        class="text-xs font-semibold tracking-[0.3em] text-rose-300 uppercase"
                    >
                        ROSH
                    </p>
                    <h2 class="text-xl font-semibold">ROSH analysis</h2>
                    <p class="max-w-3xl text-sm text-slate-300">
                        Введите только ID матча. Backend сам получит match
                        context, bracket, endDateTime и соберет core ROSH
                        запросы через STRATZ heroStats и synergy.
                    </p>
                </div>

                <form
                    class="flex flex-col gap-4 md:max-w-xl"
                    @submit.prevent="submitRosh"
                >
                    <label class="flex flex-col gap-2 text-sm">
                        Match ID
                        <input
                            v-model="roshForm.matchId"
                            type="number"
                            min="1"
                            required
                            class="rounded-md border border-slate-700 bg-slate-950 px-3 py-2"
                        />
                    </label>

                    <div
                        class="rounded-xl border border-slate-800 bg-slate-950/60 p-4 text-xs leading-6 text-slate-400"
                    >
                        Будут показаны summary, сырой JSON request и raw
                        response для ROSH-analysis, собранного из match,
                        heroStats и synergy.
                    </div>

                    <button
                        type="submit"
                        class="w-full rounded-md bg-rose-500 px-4 py-2 text-sm font-medium text-slate-950 hover:bg-rose-400 disabled:opacity-60 md:w-auto"
                        :disabled="isLoading('rosh')"
                    >
                        {{
                            isLoading('rosh')
                                ? 'Загрузка...'
                                : 'Выполнить ROSH analysis по матчу'
                        }}
                    </button>
                </form>
            </section>

            <section
                v-else-if="activeTab === 'league'"
                class="rounded-2xl border border-sky-500/20 bg-slate-900/60 p-5 shadow-xl shadow-slate-950/30"
            >
                <div class="mb-5 flex flex-col gap-2">
                    <p
                        class="text-xs font-semibold tracking-[0.3em] text-sky-300 uppercase"
                    >
                        Игры лиги
                    </p>
                    <h2 class="text-xl font-semibold">Получение игр лиги</h2>
                    <p class="max-w-3xl text-sm text-slate-300">
                        Запросите список матчей конкретной лиги, управляя
                        размером выборки и смещением.
                    </p>
                </div>

                <form
                    class="flex flex-col gap-4"
                    @submit.prevent="submitLeagueMatches"
                >
                    <label class="flex flex-col gap-2 text-sm">
                        League ID
                        <input
                            v-model="leagueForm.leagueId"
                            type="number"
                            min="1"
                            required
                            class="rounded-md border border-slate-700 bg-slate-950 px-3 py-2"
                        />
                    </label>
                    <div class="grid gap-3 md:grid-cols-2">
                        <label class="flex flex-col gap-2 text-sm">
                            Take
                            <input
                                v-model="leagueForm.take"
                                type="number"
                                min="1"
                                class="rounded-md border border-slate-700 bg-slate-950 px-3 py-2"
                            />
                        </label>
                        <label class="flex flex-col gap-2 text-sm">
                            Skip
                            <input
                                v-model="leagueForm.skip"
                                type="number"
                                min="0"
                                class="rounded-md border border-slate-700 bg-slate-950 px-3 py-2"
                            />
                        </label>
                    </div>
                    <button
                        type="submit"
                        class="w-full rounded-md bg-sky-500 px-4 py-2 text-sm font-medium text-slate-950 hover:bg-sky-400 disabled:opacity-60 md:w-auto"
                        :disabled="isLoading('league')"
                    >
                        {{
                            isLoading('league')
                                ? 'Загрузка...'
                                : 'Получить матчи'
                        }}
                    </button>
                </form>
            </section>

            <section
                v-else-if="activeTab === 'match'"
                class="rounded-2xl border border-emerald-500/20 bg-slate-900/60 p-5 shadow-xl shadow-slate-950/30"
            >
                <div class="mb-5 flex flex-col gap-2">
                    <p
                        class="text-xs font-semibold tracking-[0.3em] text-emerald-300 uppercase"
                    >
                        Матч
                    </p>
                    <h2 class="text-xl font-semibold">
                        Получение данных по матчу
                    </h2>
                    <p class="max-w-3xl text-sm text-slate-300">
                        Получите подробные данные по одному матчу: игроков,
                        пики и баны, режим игры и результат.
                    </p>
                </div>

                <form
                    class="flex flex-col gap-4 md:max-w-xl"
                    @submit.prevent="submitMatch"
                >
                    <label class="flex flex-col gap-2 text-sm">
                        Match ID
                        <input
                            v-model="matchForm.matchId"
                            type="number"
                            min="1"
                            required
                            class="rounded-md border border-slate-700 bg-slate-950 px-3 py-2"
                        />
                    </label>
                    <button
                        type="submit"
                        class="w-full rounded-md bg-emerald-500 px-4 py-2 text-sm font-medium text-slate-950 hover:bg-emerald-400 disabled:opacity-60 md:w-auto"
                        :disabled="isLoading('match')"
                    >
                        {{
                            isLoading('match') ? 'Загрузка...' : 'Получить матч'
                        }}
                    </button>
                </form>
            </section>

            <section
                v-if="errorMessage"
                class="rounded-xl border border-rose-700 bg-rose-950/60 p-4 text-sm text-rose-200"
            >
                {{ errorMessage }}
            </section>

            <section
                v-if="result"
                class="rounded-xl border border-slate-800 bg-slate-900/60 p-5"
            >
                <h2 class="mb-3 text-lg font-medium">
                    Результат:
                    <span class="font-mono text-xs tracking-wider uppercase">{{
                        result.type
                    }}</span>
                </h2>

                <div v-if="result.type === 'draft' && draftSummary" class="space-y-5">
                    <div class="rounded-2xl border border-amber-400/20 bg-slate-950/70 p-4">
                        <div class="mb-3 flex flex-col gap-1 md:flex-row md:items-center md:justify-between">
                            <div>
                                <p class="text-xs font-semibold tracking-[0.3em] text-amber-300 uppercase">
                                    Красивый вид
                                </p>
                                <h3 class="text-base font-semibold text-slate-100">
                                    Сводка по draft odds
                                </h3>
                            </div>
                            <div
                                class="inline-flex w-fit rounded-full border border-slate-700 bg-slate-900/80 px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em]"
                                :class="
                                    draftSummary.winner === 'radiant'
                                        ? 'text-emerald-300'
                                        : 'text-rose-300'
                                "
                            >
                                Winner: {{ draftSummary.winner }}
                            </div>
                        </div>

                        <div class="overflow-auto rounded-xl border border-slate-800">
                            <table class="w-full min-w-[960px] text-sm">
                                <thead class="bg-slate-900/90 text-slate-200">
                                    <tr>
                                        <th class="px-3 py-2 text-left">Match ID</th>
                                        <th class="px-3 py-2 text-left">Winner</th>
                                        <th class="px-3 py-2 text-left">Radiant odds 1</th>
                                        <th class="px-3 py-2 text-left">Radiant odds 2</th>
                                        <th class="px-3 py-2 text-left">Dire odds 1</th>
                                        <th class="px-3 py-2 text-left">Dire odds 2</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr class="border-t border-slate-800 bg-slate-950/60">
                                        <td class="px-3 py-3 font-mono text-xs text-slate-300">
                                            {{ draftSummary.match_id }}
                                        </td>
                                        <td class="px-3 py-3 capitalize">
                                            {{ draftSummary.winner }}
                                        </td>
                                        <td class="px-3 py-3 text-emerald-300">
                                            {{ formatOdds(draftSummary.radiant_odds_1) }}
                                        </td>
                                        <td class="px-3 py-3 text-emerald-300">
                                            {{ formatOdds(draftSummary.radiant_odds_2) }}
                                        </td>
                                        <td class="px-3 py-3 text-rose-300">
                                            {{ formatOdds(draftSummary.dire_odds_1) }}
                                        </td>
                                        <td class="px-3 py-3 text-rose-300">
                                            {{ formatOdds(draftSummary.dire_odds_2) }}
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
                            <h3 class="text-base font-semibold text-slate-100">
                                Сырой JSON запроса для STRATZ Plus Draft
                            </h3>
                        </div>
                        <pre
                            class="max-h-[50vh] overflow-auto rounded-lg border border-slate-800 bg-slate-950 p-4 text-xs text-slate-200"
                        >{{ formatJson(draftRequestData) }}</pre>
                    </div>

                    <div class="rounded-2xl border border-slate-800 bg-slate-950/60 p-4">
                        <div class="mb-3">
                            <p class="text-xs font-semibold tracking-[0.3em] text-slate-400 uppercase">
                                Raw
                            </p>
                            <h3 class="text-base font-semibold text-slate-100">
                                Сырой ответ STRATZ Plus Draft
                            </h3>
                        </div>
                        <pre
                            class="max-h-[50vh] overflow-auto rounded-lg border border-slate-800 bg-slate-950 p-4 text-xs text-slate-200"
                        >{{ formatJson(draftRawData) }}</pre>
                    </div>
                </div>

                <div v-else-if="result.type === 'rosh' && roshSummary" class="space-y-5">
                    <div class="rounded-2xl border border-rose-400/20 bg-slate-950/70 p-4">
                        <div class="mb-3 flex flex-col gap-1 md:flex-row md:items-center md:justify-between">
                            <div>
                                <p class="text-xs font-semibold tracking-[0.3em] text-rose-300 uppercase">
                                    Красивый вид
                                </p>
                                <h3 class="text-base font-semibold text-slate-100">
                                    Сводка по ROSH analysis
                                </h3>
                            </div>
                            <div
                                class="inline-flex w-fit rounded-full border border-slate-700 bg-slate-900/80 px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em]"
                                :class="
                                    roshSummary.winner === 'radiant'
                                        ? 'text-emerald-300'
                                        : 'text-rose-300'
                                "
                            >
                                Winner: {{ roshSummary.winner }}
                            </div>
                        </div>

                        <div class="overflow-auto rounded-xl border border-slate-800">
                            <table class="w-full min-w-[1440px] text-sm">
                                <thead class="bg-slate-900/90 text-slate-200">
                                    <tr>
                                        <th class="px-3 py-2 text-left">Match ID</th>
                                        <th class="px-3 py-2 text-left">Winner</th>
                                        <th class="px-3 py-2 text-left">Radiant odds 1</th>
                                        <th class="px-3 py-2 text-left">Radiant odds 2</th>
                                        <th class="px-3 py-2 text-left">Dire odds 1</th>
                                        <th class="px-3 py-2 text-left">Dire odds 2</th>
                                        <th class="px-3 py-2 text-left">Radiant team</th>
                                        <th class="px-3 py-2 text-left">Dire team</th>
                                        <th class="px-3 py-2 text-left">Bracket</th>
                                        <th class="px-3 py-2 text-left">Bracket basic</th>
                                        <th class="px-3 py-2 text-left">Date time</th>
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
                                        <td class="px-3 py-3 text-slate-100">
                                            {{ roshSummary.radiant_team }}
                                        </td>
                                        <td class="px-3 py-3 text-slate-100">
                                            {{ roshSummary.dire_team }}
                                        </td>
                                        <td class="px-3 py-3 font-mono text-xs text-rose-200">
                                            {{ roshSummary.bracket }}
                                        </td>
                                        <td class="px-3 py-3 font-mono text-xs text-rose-200">
                                            {{ roshSummary.bracket_basic }}
                                        </td>
                                        <td class="px-3 py-3 font-mono text-xs text-slate-300">
                                            {{ formatUnixDate(roshSummary.date_time) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div
                        v-if="roshGoogleSheets"
                        class="rounded-2xl border border-cyan-400/20 bg-slate-950/60 p-4"
                    >
                        <div class="mb-3">
                            <p class="text-xs font-semibold tracking-[0.3em] text-cyan-200 uppercase">
                                Sync
                            </p>
                            <h3 class="text-base font-semibold text-slate-100">
                                Google Sheets write-back
                            </h3>
                            <p class="mt-1 text-xs leading-5 text-slate-400">
                                Sheet {{ roshGoogleSheets.sheet_title }}, row
                                {{ roshGoogleSheets.row }} was updated after the ROSH calculation.
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
                            <h3 class="text-base font-semibold text-slate-100">
                                Minute-by-minute ROSH graph data
                            </h3>
                            <p class="mt-1 text-xs leading-5 text-slate-400">
                                Each row is the exact per-minute value used to draw the ROSH win-probability curve.
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
                            <h3 class="text-base font-semibold text-slate-100">
                                Сырой JSON запроса для ROSH analysis
                            </h3>
                        </div>
                        <pre
                            class="max-h-[50vh] overflow-auto rounded-lg border border-slate-800 bg-slate-950 p-4 text-xs text-slate-200"
                        >{{ formatJson(roshRequestData) }}</pre>
                    </div>

                    <div class="rounded-2xl border border-slate-800 bg-slate-950/60 p-4">
                        <div class="mb-3">
                            <p class="text-xs font-semibold tracking-[0.3em] text-slate-400 uppercase">
                                Raw
                            </p>
                            <h3 class="text-base font-semibold text-slate-100">
                                Сырой ответ STRATZ ROSH analysis
                            </h3>
                        </div>
                        <pre
                            class="max-h-[50vh] overflow-auto rounded-lg border border-slate-800 bg-slate-950 p-4 text-xs text-slate-200"
                        >{{ formatJson(roshRawData) }}</pre>
                    </div>
                </div>

                <div v-else>
                    <pre
                        class="max-h-[50vh] overflow-auto rounded-lg border border-slate-800 bg-slate-950 p-4 text-xs text-slate-200"
                    >{{ formatJson(result.data) }}</pre>
                </div>
            </section>
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue';

type StratzTab = 'draft' | 'rosh' | 'league' | 'match';

type DraftFormattedResult = {
    match_id: number;
    winner: 'radiant' | 'dire';
    radiant_odds_1: number | null;
    radiant_odds_2: number | null;
    dire_odds_1: number | null;
    dire_odds_2: number | null;
};

type DraftResultPayload = {
    formatted?: DraftFormattedResult;
    request?: unknown;
    raw?: unknown;
};

type RoshFormattedResult = {
    match_id: number;
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
    data: any;
};

const tabs: Array<{
    id: StratzTab;
    label: string;
    shortLabel: string;
    description: string;
    activeClasses: string;
    badgeClasses: string;
}> = [
    {
        id: 'draft',
        label: 'Таб итоговых вычислений',
        shortLabel: 'Draft',
        description: 'Собрать draft-расчет автоматически по одному match ID.',
        activeClasses: 'border-amber-400/50 bg-amber-500/10 text-amber-50',
        badgeClasses: 'border-amber-300/40 bg-amber-300/10 text-amber-100',
    },
    {
        id: 'rosh',
        label: 'Таб ROSH-запроса',
        shortLabel: 'ROSH',
        description: 'Собрать ROSH-analysis по одному match ID и получить request/raw ответ.',
        activeClasses: 'border-rose-400/50 bg-rose-500/10 text-rose-50',
        badgeClasses: 'border-rose-300/40 bg-rose-300/10 text-rose-100',
    },
    {
        id: 'league',
        label: 'Таб получения игр лиги',
        shortLabel: 'League',
        description: 'Запросить пачку матчей по league ID с take и skip.',
        activeClasses: 'border-sky-400/50 bg-sky-500/10 text-sky-50',
        badgeClasses: 'border-sky-300/40 bg-sky-300/10 text-sky-100',
    },
    {
        id: 'match',
        label: 'Таб получения данных по матчу',
        shortLabel: 'Match',
        description: 'Получить подробную информацию по конкретному match ID.',
        activeClasses: 'border-emerald-400/50 bg-emerald-500/10 text-emerald-50',
        badgeClasses: 'border-emerald-300/40 bg-emerald-300/10 text-emerald-100',
    },
];

const activeTab = ref<StratzTab>('draft');

const leagueForm = reactive({
    leagueId: '',
    take: '20',
    skip: '0',
});

const matchForm = reactive({
    matchId: '',
});

const draftForm = reactive({
    matchId: '',
});

const roshForm = reactive({
    matchId: '',
});

const loadingAction = ref<string | null>(null);
const errorMessage = ref('');
const result = ref<StratzResult | null>(null);

const csrfToken =
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content || '';

const draftSummary = computed<DraftFormattedResult | null>(() => {
    if (result.value?.type !== 'draft') {
        return null;
    }

    return (result.value.data as DraftResultPayload)?.formatted ?? null;
});

const draftRawData = computed(() => {
    if (result.value?.type !== 'draft') {
        return null;
    }

    return (result.value.data as DraftResultPayload)?.raw ?? null;
});

const draftRequestData = computed(() => {
    if (result.value?.type !== 'draft') {
        return null;
    }

    return (result.value.data as DraftResultPayload)?.request ?? null;
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

const isLoading = (action: string) => loadingAction.value === action;

const jsonHeaders = () => ({
    'Content-Type': 'application/json',
    Accept: 'application/json',
    'X-CSRF-Token': csrfToken,
});

const formatJson = (value: unknown) => JSON.stringify(value, null, 2);

const formatOdds = (value: number | null) => {
    if (value === null) {
        return '—';
    }

    return `${(value * 100).toFixed(2)}%`;
};

const formatUnixDate = (value: number) => {
    if (!Number.isFinite(value)) {
        return '—';
    }

    return new Date(value * 1000).toLocaleString('ru-RU');
};

const formatPercentValue = (value: number | null | undefined) => {
    if (typeof value !== 'number' || !Number.isFinite(value)) {
        return '—';
    }

    return `${value.toFixed(1)}%`;
};

const formatSignedPercentValue = (value: number | null | undefined) => {
    if (typeof value !== 'number' || !Number.isFinite(value)) {
        return '—';
    }

    return `${value > 0 ? '+' : ''}${value.toFixed(1)}%`;
};

const formatMinuteWindow = (start: number, end: number) => {
    const startLabel = `${String(start).padStart(2, '0')}:00`;
    const endLabel = `${String(end).padStart(2, '0')}:00`;

    return start === end ? startLabel : `${startLabel} - ${endLabel}`;
};

const formatAdvantageSide = (value: RoshMinuteTableRow['advantage_side']) => {
    if (value === 'radiant') {
        return 'Radiant';
    }

    if (value === 'dire') {
        return 'Dire';
    }

    return 'Even';
};

const request = async (action: string, url: string, payload: unknown) => {
    loadingAction.value = action;
    errorMessage.value = '';

    try {
        const response = await fetch(url, {
            method: 'post',
            headers: jsonHeaders(),
            credentials: 'same-origin',
            body: JSON.stringify(payload),
        });

        const contentType = response.headers.get('content-type') || '';
        const body = contentType.includes('application/json')
            ? await response.json()
            : await response.text();

        if (!response.ok) {
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
        errorMessage.value =
            error instanceof Error ? error.message : String(error);
        throw error;
    } finally {
        loadingAction.value = null;
    }
};

const submitLeagueMatches = async () => {
    if (!leagueForm.leagueId) {
        errorMessage.value = 'League ID обязателен';
        return;
    }

    await request('league', '/stratz/league-matches', {
        league_id: Number(leagueForm.leagueId),
        take: leagueForm.take ? Number(leagueForm.take) : undefined,
        skip: leagueForm.skip ? Number(leagueForm.skip) : undefined,
    });
};

const submitMatch = async () => {
    if (!matchForm.matchId) {
        errorMessage.value = 'Match ID обязателен';
        return;
    }

    await request('match', '/stratz/match', {
        match_id: Number(matchForm.matchId),
    });
};

const submitDraft = async () => {
    if (!draftForm.matchId) {
        errorMessage.value = 'Match ID обязателен для draft-расчета';
        return;
    }

    await request('draft', '/stratz/draft', {
        match_id: Number(draftForm.matchId),
    });
};

const submitRosh = async () => {
    if (!roshForm.matchId) {
        errorMessage.value = 'Match ID обязателен для ROSH-анализа';
        return;
    }

    await request('rosh', '/stratz/rosh', {
        match_id: Number(roshForm.matchId),
    });
};
</script>
