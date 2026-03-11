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
                            class="text-xs font-semibold uppercase tracking-[0.35em] text-cyan-300/80"
                        >
                            STRATZ Workspace
                        </p>
                        <h1 class="text-3xl font-semibold tracking-tight">
                            Инструменты для матчей, лиг и драфта
                        </h1>
                        <p class="max-w-3xl text-sm leading-6 text-slate-300">
                            Разделите работу по сценариям: итоговые draft-вычисления,
                            список игр лиги, данные по матчу и выгрузка про-игроков.
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
                                class="rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.25em]"
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
                        class="text-xs font-semibold uppercase tracking-[0.3em] text-amber-300"
                    >
                        Итоговые вычисления
                    </p>
                    <h2 class="text-xl font-semibold">Draft</h2>
                    <p class="max-w-3xl text-sm text-slate-300">
                        Соберите 10 слотов, при необходимости укажите матч,
                        версию игры и баны, затем получите рассчитанные исходы.
                    </p>
                </div>

                <form class="flex flex-col gap-4" @submit.prevent="submitDraft">
                    <div class="grid gap-3 md:grid-cols-3">
                        <label class="flex flex-col gap-2 text-sm">
                            Match ID
                            <input
                                v-model="draftForm.matchId"
                                type="number"
                                min="1"
                                class="rounded-md border border-slate-700 bg-slate-950 px-3 py-2"
                            />
                        </label>
                        <label class="flex flex-col gap-2 text-sm">
                            Game Mode
                            <input
                                v-model="draftForm.gameMode"
                                type="number"
                                min="1"
                                class="rounded-md border border-slate-700 bg-slate-950 px-3 py-2"
                            />
                        </label>
                        <label class="flex flex-col gap-2 text-sm">
                            Game Version ID
                            <input
                                v-model="draftForm.gameVersionId"
                                type="number"
                                min="1"
                                class="rounded-md border border-slate-700 bg-slate-950 px-3 py-2"
                            />
                        </label>
                    </div>

                    <label class="flex flex-col gap-2 text-sm">
                        Bans (через запятую)
                        <input
                            v-model="draftForm.bans"
                            placeholder="1,2,3"
                            class="rounded-md border border-slate-700 bg-slate-950 px-3 py-2"
                        />
                    </label>

                    <div
                        class="overflow-auto rounded-lg border border-slate-800"
                    >
                        <table class="w-full min-w-[720px] text-sm">
                            <thead class="bg-slate-900/80 text-slate-200">
                                <tr>
                                    <th class="px-3 py-2 text-left">Slot</th>
                                    <th class="px-3 py-2 text-left">Hero</th>
                                    <th class="px-3 py-2 text-left">
                                        Steam Account ID
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="player in draftForm.players"
                                    :key="player.slot"
                                    class="border-t border-slate-800"
                                >
                                    <td
                                        class="px-3 py-2 font-mono text-xs text-slate-300"
                                    >
                                        {{ player.slot }}
                                    </td>
                                    <td class="px-3 py-2">
                                        <select
                                            v-model="player.heroId"
                                            required
                                            class="w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-2"
                                        >
                                            <option value="">
                                                Выберите героя
                                            </option>
                                            <option
                                                v-for="hero in heroes"
                                                :key="hero.id"
                                                :value="hero.id"
                                            >
                                                {{ hero.title }}
                                            </option>
                                        </select>
                                    </td>
                                    <td class="px-3 py-2">
                                        <input
                                            v-model="player.steamAccountId"
                                            type="number"
                                            min="1"
                                            placeholder="необязательно"
                                            class="w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-2"
                                        />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div
                        class="flex flex-col gap-3 rounded-xl border border-slate-800 bg-slate-950/60 p-4 md:flex-row md:items-center md:justify-between"
                    >
                        <p class="text-xs text-slate-400">
                            {{ heroes.length }} героев в enum. Слоты 0-4 — Radiant,
                            5-9 — Dire.
                        </p>
                        <button
                            type="submit"
                            class="rounded-md bg-amber-500 px-4 py-2 text-sm font-medium text-slate-950 hover:bg-amber-400 disabled:opacity-60"
                            :disabled="isLoading('draft')"
                        >
                            {{
                                isLoading('draft')
                                    ? 'Загрузка...'
                                    : 'Выполнить draft'
                            }}
                        </button>
                    </div>
                </form>
            </section>

            <section
                v-else-if="activeTab === 'league'"
                class="rounded-2xl border border-sky-500/20 bg-slate-900/60 p-5 shadow-xl shadow-slate-950/30"
            >
                <div class="mb-5 flex flex-col gap-2">
                    <p
                        class="text-xs font-semibold uppercase tracking-[0.3em] text-sky-300"
                    >
                        Игры лиги
                    </p>
                    <h2 class="text-xl font-semibold">
                        Получение игр лиги
                    </h2>
                    <p class="max-w-3xl text-sm text-slate-300">
                        Запросите список матчей конкретной лиги, управляя размером
                        выборки и смещением.
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
                        class="text-xs font-semibold uppercase tracking-[0.3em] text-emerald-300"
                    >
                        Матч
                    </p>
                    <h2 class="text-xl font-semibold">
                        Получение данных по матчу
                    </h2>
                    <p class="max-w-3xl text-sm text-slate-300">
                        Получите подробные данные по одному матчу: игроков, пики и
                        баны, режим игры и результат.
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
                            isLoading('match')
                                ? 'Загрузка...'
                                : 'Получить матч'
                        }}
                    </button>
                </form>
            </section>

            <section
                v-else
                class="rounded-2xl border border-violet-500/20 bg-slate-900/60 p-5 shadow-xl shadow-slate-950/30"
            >
                <div class="mb-5 flex flex-col gap-2">
                    <p
                        class="text-xs font-semibold uppercase tracking-[0.3em] text-violet-300"
                    >
                        Про-игроки
                    </p>
                    <h2 class="text-xl font-semibold">
                        Получение Про-игроков
                    </h2>
                    <p class="max-w-3xl text-sm text-slate-300">
                        Запросите актуальный список профессиональных игроков STRATZ
                        с никами, странами, командами и позицией.
                    </p>
                </div>

                <button
                    type="button"
                    @click="submitProPlayers"
                    class="w-full rounded-md bg-violet-500 px-4 py-2 text-sm font-medium text-slate-950 hover:bg-violet-400 disabled:opacity-60 md:w-auto"
                    :disabled="isLoading('pro_players')"
                >
                    {{
                        isLoading('pro_players')
                            ? 'Загрузка...'
                            : 'Получить pro-игроков'
                    }}
                </button>
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
                    <span class="font-mono text-xs uppercase tracking-wider">{{
                        result.type
                    }}</span>
                </h2>
                <div v-if="result.type === 'pro_players'">
                    <p class="mb-3 text-sm text-slate-300">
                        Найдено
                        {{ Array.isArray(result.data) ? result.data.length : 0 }}
                        pro-игроков.
                    </p>
                    <div
                        class="overflow-auto rounded-lg border border-slate-800"
                    >
                        <table class="w-full min-w-[900px] text-sm">
                            <thead class="bg-slate-900/80 text-slate-200">
                                <tr>
                                    <th class="px-3 py-2 text-left">
                                        Player ID
                                    </th>
                                    <th class="px-3 py-2 text-left">
                                        Nickname
                                    </th>
                                    <th class="px-3 py-2 text-left">
                                        Real Name
                                    </th>
                                    <th class="px-3 py-2 text-left">Team ID</th>
                                    <th class="px-3 py-2 text-left">
                                        Position
                                    </th>
                                    <th class="px-3 py-2 text-left">
                                        Countries
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="player in result.data"
                                    :key="`${player.id}-${player.teamId}`"
                                    class="border-t border-slate-800"
                                >
                                    <td
                                        class="px-3 py-2 font-mono text-xs text-slate-300"
                                    >
                                        {{ player.id || '—' }}
                                    </td>
                                    <td class="px-3 py-2">
                                        {{ player.name || '—' }}
                                    </td>
                                    <td class="px-3 py-2">
                                        {{ player.realName || '—' }}
                                    </td>
                                    <td class="px-3 py-2">
                                        {{ player.teamId || '—' }}
                                    </td>
                                    <td class="px-3 py-2">
                                        {{ player.position || '—' }}
                                    </td>
                                    <td class="px-3 py-2">
                                        {{
                                            (Array.isArray(player.countries)
                                                ? player.countries
                                                : []
                                            ).join(', ') || '—'
                                        }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div v-else>
                    <pre
                        class="max-h-[50vh] overflow-auto rounded-lg border border-slate-800 bg-slate-950 p-4 text-xs text-slate-200"
                        >{{ formatJson(result.data) }}
                    </pre>
                </div>
            </section>
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue';

interface HeroPayload {
    id: number;
    name: string;
    title: string;
}

type StratzTab = 'draft' | 'league' | 'match' | 'proPlayers';

const props = defineProps<{ heroes: HeroPayload[] }>();

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
        description:
            'Собрать драфт, баны и слоты игроков для итоговых расчетов.',
        activeClasses: 'border-amber-400/50 bg-amber-500/10 text-amber-50',
        badgeClasses: 'border-amber-300/40 bg-amber-300/10 text-amber-100',
    },
    {
        id: 'league',
        label: 'Таб получения игр лиги',
        shortLabel: 'League',
        description:
            'Запросить пачку матчей по league ID с take и skip.',
        activeClasses: 'border-sky-400/50 bg-sky-500/10 text-sky-50',
        badgeClasses: 'border-sky-300/40 bg-sky-300/10 text-sky-100',
    },
    {
        id: 'match',
        label: 'Таб получения данных по матчу',
        shortLabel: 'Match',
        description:
            'Получить подробную информацию по конкретному match ID.',
        activeClasses:
            'border-emerald-400/50 bg-emerald-500/10 text-emerald-50',
        badgeClasses:
            'border-emerald-300/40 bg-emerald-300/10 text-emerald-100',
    },
    {
        id: 'proPlayers',
        label: 'Таб получения Про-игроков',
        shortLabel: 'Pro',
        description:
            'Выгрузить список профессиональных игроков и их метаданные.',
        activeClasses: 'border-violet-400/50 bg-violet-500/10 text-violet-50',
        badgeClasses: 'border-violet-300/40 bg-violet-300/10 text-violet-100',
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
    gameMode: '',
    gameVersionId: '',
    bans: '',
    players: Array.from({ length: 10 }, (_, slot) => ({
        slot,
        heroId: '',
        steamAccountId: '',
    })),
});

const loadingAction = ref<string | null>(null);
const errorMessage = ref('');
const result = ref<{ type: string; data: any } | null>(null);

const heroes = computed(() => props.heroes);

const csrfToken =
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content || '';

const isLoading = (action: string) => loadingAction.value === action;

const jsonHeaders = () => ({
    'Content-Type': 'application/json',
    Accept: 'application/json',
    'X-CSRF-Token': csrfToken,
});

const formatJson = (value: any) => JSON.stringify(value, null, 2);

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

const submitProPlayers = async () => {
    await request('pro_players', '/stratz/pro-players', {});
};

const submitDraft = async () => {
    const players = draftForm.players.map((player) => ({
        slot: player.slot,
        heroId: player.heroId ? Number(player.heroId) : null,
        steamAccountId: player.steamAccountId
            ? Number(player.steamAccountId)
            : undefined,
    }));

    if (
        players.some(
            (player) => player.heroId === null || Number.isNaN(player.heroId),
        )
    ) {
        errorMessage.value = 'Выберите героя для каждого слота.';
        return;
    }

    const bans = draftForm.bans
        .split(',')
        .map((item) => item.trim())
        .filter((item) => item !== '' && /^\d+$/.test(item))
        .map(Number);

    await request('draft', '/stratz/draft', {
        match_id: draftForm.matchId ? Number(draftForm.matchId) : undefined,
        game_mode: draftForm.gameMode ? Number(draftForm.gameMode) : undefined,
        game_version_id: draftForm.gameVersionId
            ? Number(draftForm.gameVersionId)
            : undefined,
        bans,
        hero_ids: players.map((player) => player.heroId),
        player_ids: players.map((player) => player.steamAccountId ?? null),
    });
};
</script>