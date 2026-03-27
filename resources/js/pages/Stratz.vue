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
                v-else-if="activeTab === 'heroes'"
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
                    <label
                        class="flex items-start gap-3 rounded-2xl border border-cyan-500/20 bg-cyan-500/8 p-4 text-sm text-slate-200 transition has-checked:border-cyan-400/45 has-checked:bg-cyan-500/12"
                    >
                        <input
                            v-model="heroForm.considerPlayers"
                            type="checkbox"
                            class="mt-0.5 h-4 w-4 rounded border border-slate-600 bg-slate-950 text-cyan-400 focus:ring-2 focus:ring-cyan-400/60"
                        />
                        <div class="space-y-1">
                            <div class="font-semibold text-white">Учитывать героев</div>
                            <p class="max-w-3xl text-sm leading-6 text-slate-400">
                                Включает расширенный режим: для каждого слота можно указать про-игрока и затем учесть его
                                статистику на выбранном герое. Если переключатель выключен, расчет идет строго по старому
                                hero-only формату.
                            </p>
                        </div>
                    </label>

                    <div class="grid gap-4 xl:grid-cols-2">
                        <section class="rounded-2xl border border-emerald-500/20 bg-slate-950/70 p-5">
                            <div class="mb-4 space-y-1">
                                <p class="text-xs font-semibold tracking-[0.3em] text-emerald-300 uppercase">
                                    Radiant
                                </p>
                            </div>

                            <div class="space-y-4">
                                <label class="flex flex-col gap-2 text-sm text-slate-200">
                                    Сохраненный состав Radiant
                                    <select
                                        v-model="heroTeamPresets.radiant"
                                        class="rounded-xl border border-slate-700 bg-slate-900 px-4 py-3 text-sm text-slate-100 outline-none transition focus:border-emerald-400"
                                        @change="handleSavedTeamSelection('radiant', $event)"
                                    >
                                        <option value="">Ручной ввод</option>
                                        <option
                                            v-for="team in savedTeamsSorted"
                                            :key="`radiant-team-${team.slug}`"
                                            :value="team.slug"
                                        >
                                            {{ team.name }} · {{ countFilledSavedTeamSlots(team) }}/5
                                        </option>
                                    </select>
                                    <span class="text-xs leading-5 text-slate-500">
                                        Подставляет название команды и игроков по ролям. Герои остаются без изменений.
                                    </span>
                                </label>

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
                                                v-if="shouldShowHeroPicker('radiant', role.position - 1)"
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

                                        <div
                                            v-if="heroForm.considerPlayers"
                                            class="relative space-y-2"
                                            data-player-picker
                                        >
                                            <div
                                                class="rounded-2xl border border-slate-700/80 bg-slate-900/80 px-3 py-3 transition focus-within:border-emerald-400/60"
                                            >
                                                <div class="mb-2 flex items-center justify-between gap-3">
                                                    <span class="text-[11px] font-semibold tracking-[0.22em] text-emerald-200 uppercase">
                                                        Про-игрок
                                                    </span>
                                                    <span class="text-[11px] text-slate-500">
                                                    Liquipedia PRO
                                                    </span>
                                                </div>

                                                <div class="flex items-center gap-3">
                                                    <div class="min-w-0 flex-1">
                                                        <input
                                                            :value="getPlayerValue('radiant', role.position - 1)"
                                                            :data-player-input="playerPickerKey('radiant', role.position - 1)"
                                                            type="text"
                                                            autocomplete="off"
                                                            class="w-full bg-transparent text-sm text-slate-100 outline-none placeholder:text-slate-500"
                                                            :placeholder="`Никнейм про-игрока для ${role.label}`"
                                                            @focus="handlePlayerFocus('radiant', role.position - 1)"
                                                            @input="handlePlayerInput('radiant', role.position - 1, $event)"
                                                            @keydown.escape="closePlayerPicker"
                                                        />
                                                    </div>

                                                    <button
                                                        v-if="getPlayerValue('radiant', role.position - 1)"
                                                        type="button"
                                                        tabindex="-1"
                                                        class="rounded-lg border border-slate-700 px-2 py-1 text-xs font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white"
                                                        @click="clearPlayerSelection('radiant', role.position - 1)"
                                                    >
                                                        Г—
                                                    </button>
                                                </div>

                                                <p
                                                    class="mt-2 text-[11px] leading-5"
                                                    :class="getPlayerHintClass('radiant', role.position - 1)"
                                                >
                                                    {{ getPlayerHint('radiant', role.position - 1) }}
                                                </p>
                                            </div>

                                            <div
                                                v-if="selectedPlayerFor('radiant', role.position - 1)"
                                                class="rounded-2xl border border-emerald-500/20 bg-emerald-500/8 px-3 py-3 text-xs text-slate-200"
                                            >
                                                <div class="font-semibold text-emerald-100">
                                                    {{ getPlayerDisplayName(selectedPlayerFor('radiant', role.position - 1)!) }}
                                                </div>
                                                <div class="mt-1 text-slate-300">
                                                    {{ getPlayerMetaLine(selectedPlayerFor('radiant', role.position - 1)!) }}
                                                </div>
                                                <div
                                                    v-if="selectedPlayerFor('radiant', role.position - 1)!.aliases.length > 0"
                                                    class="mt-1 text-slate-400"
                                                >
                                                    Aliases: {{ selectedPlayerFor('radiant', role.position - 1)!.aliases.join(', ') }}
                                                </div>
                                            </div>

                                            <div
                                                v-if="shouldShowPlayerPicker('radiant', role.position - 1)"
                                                class="absolute inset-x-0 top-full z-30 mt-2 overflow-hidden rounded-2xl border border-slate-700 bg-slate-950/98 shadow-2xl shadow-slate-950/70"
                                            >
                                                <div class="border-b border-slate-800 px-3 py-2 text-[11px] font-semibold tracking-[0.22em] text-slate-400 uppercase">
                                                    Кандидаты Liquipedia
                                                </div>

                                                <div v-if="getPlayerSearchStatus('radiant', role.position - 1) === 'searching'" class="px-3 py-4 text-sm text-slate-400">
                                                    Ищем про-игроков...
                                                </div>

                                                <div
                                                    v-else-if="getPlayerSearchStatus('radiant', role.position - 1) === 'error'"
                                                    class="px-3 py-4 text-sm text-rose-300"
                                                >
                                                    {{ getPlayerSearchError('radiant', role.position - 1) }}
                                                </div>

                                                <div
                                                    v-else-if="getPlayerMatches('radiant', role.position - 1).length === 0"
                                                    class="px-3 py-4 text-sm text-slate-500"
                                                >
                                                    Ничего не найдено. Попробуйте другой ник или alias.
                                                </div>

                                                <div v-else class="max-h-72 overflow-y-auto p-2">
                                                    <button
                                                        v-for="player in getPlayerMatches('radiant', role.position - 1)"
                                                        :key="player.steam_account_id"
                                                        type="button"
                                                        class="flex w-full flex-col gap-1 rounded-xl px-3 py-2.5 text-left transition hover:bg-slate-900 hover:text-white"
                                                        @mousedown.prevent="selectPlayer('radiant', role.position - 1, player)"
                                                    >
                                                        <div class="font-semibold text-white">
                                                            {{ getPlayerDisplayName(player) }}
                                                        </div>
                                                        <div class="text-xs text-slate-300">
                                                            {{ getPlayerMetaLine(player) }}
                                                        </div>
                                                        <div v-if="player.aliases.length > 0" class="text-[11px] text-slate-500">
                                                            Aliases: {{ player.aliases.join(', ') }}
                                                        </div>
                                                    </button>
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
                                    Сохраненный состав Dire
                                    <select
                                        v-model="heroTeamPresets.dire"
                                        class="rounded-xl border border-slate-700 bg-slate-900 px-4 py-3 text-sm text-slate-100 outline-none transition focus:border-rose-400"
                                        @change="handleSavedTeamSelection('dire', $event)"
                                    >
                                        <option value="">Ручной ввод</option>
                                        <option
                                            v-for="team in savedTeamsSorted"
                                            :key="`dire-team-${team.slug}`"
                                            :value="team.slug"
                                        >
                                            {{ team.name }} · {{ countFilledSavedTeamSlots(team) }}/5
                                        </option>
                                    </select>
                                    <span class="text-xs leading-5 text-slate-500">
                                        Подставляет название команды и игроков по ролям. Герои остаются без изменений.
                                    </span>
                                </label>

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
                                                v-if="shouldShowHeroPicker('dire', role.position - 1)"
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

                                        <div
                                            v-if="heroForm.considerPlayers"
                                            class="relative space-y-2"
                                            data-player-picker
                                        >
                                            <div
                                                class="rounded-2xl border border-slate-700/80 bg-slate-900/80 px-3 py-3 transition focus-within:border-rose-400/60"
                                            >
                                                <div class="mb-2 flex items-center justify-between gap-3">
                                                    <span class="text-[11px] font-semibold tracking-[0.22em] text-rose-200 uppercase">
                                                        Про-игрок
                                                    </span>
                                                    <span class="text-[11px] text-slate-500">
                                                    Liquipedia PRO
                                                    </span>
                                                </div>

                                                <div class="flex items-center gap-3">
                                                    <div class="min-w-0 flex-1">
                                                        <input
                                                            :value="getPlayerValue('dire', role.position - 1)"
                                                            :data-player-input="playerPickerKey('dire', role.position - 1)"
                                                            type="text"
                                                            autocomplete="off"
                                                            class="w-full bg-transparent text-sm text-slate-100 outline-none placeholder:text-slate-500"
                                                            :placeholder="`Никнейм про-игрока для ${role.label}`"
                                                            @focus="handlePlayerFocus('dire', role.position - 1)"
                                                            @input="handlePlayerInput('dire', role.position - 1, $event)"
                                                            @keydown.escape="closePlayerPicker"
                                                        />
                                                    </div>

                                                    <button
                                                        v-if="getPlayerValue('dire', role.position - 1)"
                                                        type="button"
                                                        tabindex="-1"
                                                        class="rounded-lg border border-slate-700 px-2 py-1 text-xs font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white"
                                                        @click="clearPlayerSelection('dire', role.position - 1)"
                                                    >
                                                        Г—
                                                    </button>
                                                </div>

                                                <p
                                                    class="mt-2 text-[11px] leading-5"
                                                    :class="getPlayerHintClass('dire', role.position - 1)"
                                                >
                                                    {{ getPlayerHint('dire', role.position - 1) }}
                                                </p>
                                            </div>

                                            <div
                                                v-if="selectedPlayerFor('dire', role.position - 1)"
                                                class="rounded-2xl border border-rose-500/20 bg-rose-500/8 px-3 py-3 text-xs text-slate-200"
                                            >
                                                <div class="font-semibold text-rose-100">
                                                    {{ getPlayerDisplayName(selectedPlayerFor('dire', role.position - 1)!) }}
                                                </div>
                                                <div class="mt-1 text-slate-300">
                                                    {{ getPlayerMetaLine(selectedPlayerFor('dire', role.position - 1)!) }}
                                                </div>
                                                <div
                                                    v-if="selectedPlayerFor('dire', role.position - 1)!.aliases.length > 0"
                                                    class="mt-1 text-slate-400"
                                                >
                                                    Aliases: {{ selectedPlayerFor('dire', role.position - 1)!.aliases.join(', ') }}
                                                </div>
                                            </div>

                                            <div
                                                v-if="shouldShowPlayerPicker('dire', role.position - 1)"
                                                class="absolute inset-x-0 top-full z-30 mt-2 overflow-hidden rounded-2xl border border-slate-700 bg-slate-950/98 shadow-2xl shadow-slate-950/70"
                                            >
                                                <div class="border-b border-slate-800 px-3 py-2 text-[11px] font-semibold tracking-[0.22em] text-slate-400 uppercase">
                                                    Кандидаты Liquipedia
                                                </div>

                                                <div v-if="getPlayerSearchStatus('dire', role.position - 1) === 'searching'" class="px-3 py-4 text-sm text-slate-400">
                                                    Ищем про-игроков...
                                                </div>

                                                <div
                                                    v-else-if="getPlayerSearchStatus('dire', role.position - 1) === 'error'"
                                                    class="px-3 py-4 text-sm text-rose-300"
                                                >
                                                    {{ getPlayerSearchError('dire', role.position - 1) }}
                                                </div>

                                                <div
                                                    v-else-if="getPlayerMatches('dire', role.position - 1).length === 0"
                                                    class="px-3 py-4 text-sm text-slate-500"
                                                >
                                                    Ничего не найдено. Попробуйте другой ник или alias.
                                                </div>

                                                <div v-else class="max-h-72 overflow-y-auto p-2">
                                                    <button
                                                        v-for="player in getPlayerMatches('dire', role.position - 1)"
                                                        :key="player.steam_account_id"
                                                        type="button"
                                                        class="flex w-full flex-col gap-1 rounded-xl px-3 py-2.5 text-left transition hover:bg-slate-900 hover:text-white"
                                                        @mousedown.prevent="selectPlayer('dire', role.position - 1, player)"
                                                    >
                                                        <div class="font-semibold text-white">
                                                            {{ getPlayerDisplayName(player) }}
                                                        </div>
                                                        <div class="text-xs text-slate-300">
                                                            {{ getPlayerMetaLine(player) }}
                                                        </div>
                                                        <div v-if="player.aliases.length > 0" class="text-[11px] text-slate-500">
                                                            Aliases: {{ player.aliases.join(', ') }}
                                                        </div>
                                                    </button>
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
                v-else-if="activeTab === 'teams'"
                class="rounded-3xl border border-amber-500/20 bg-slate-900/60 p-6 shadow-xl shadow-slate-950/30"
            >
                <div class="mb-5 flex flex-col gap-2">
                    <p class="text-xs font-semibold tracking-[0.3em] text-amber-300 uppercase">
                        Teams
                    </p>
                    <h2 class="text-xl font-semibold text-white">Сохраненные составы</h2>
                    <p class="max-w-3xl text-sm leading-6 text-slate-300">
                        Здесь можно вручную собрать и сохранить команду по ролям, выбирая игроков через тот же Liquipedia
                        lookup. Сохраненный состав затем подставляется в hero-based ROSH одним кликом.
                    </p>
                </div>

                <div class="grid gap-5 xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
                    <section class="rounded-2xl border border-slate-800 bg-slate-950/70 p-5">
                        <div class="mb-4 flex items-center justify-between gap-3">
                            <div>
                                <p class="text-xs font-semibold tracking-[0.3em] text-amber-300 uppercase">
                                    Library
                                </p>
                                <h3 class="mt-1 text-lg font-semibold text-white">Команды из репозитория</h3>
                            </div>

                            <button
                                type="button"
                                class="rounded-xl border border-amber-400/30 bg-amber-500/10 px-3 py-2 text-sm font-semibold text-amber-100 transition hover:border-amber-300/50 hover:bg-amber-500/15"
                                @click="startCreatingTeamRoster"
                            >
                                Новая команда
                            </button>
                        </div>

                        <div
                            v-if="savedTeamsSorted.length === 0"
                            class="rounded-2xl border border-dashed border-slate-700 bg-slate-900/60 px-4 py-6 text-sm leading-6 text-slate-400"
                        >
                            Пока нет сохраненных составов. Создайте первый справа и сохраните его в репозиторий.
                        </div>

                        <div v-else class="space-y-3">
                            <article
                                v-for="team in savedTeamsSorted"
                                :key="team.slug"
                                class="rounded-2xl border border-slate-800 bg-slate-900/70 p-4"
                            >
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <div class="text-base font-semibold text-white">{{ team.name }}</div>
                                        <div class="mt-1 text-xs leading-5 text-slate-400">
                                            {{ countFilledSavedTeamSlots(team) }}/5 слотов · обновлено {{ formatSavedTeamUpdatedAt(team.updated_at) }}
                                        </div>
                                    </div>

                                    <button
                                        type="button"
                                        class="rounded-xl border border-slate-700 px-3 py-2 text-xs font-semibold text-slate-200 transition hover:border-amber-300/50 hover:text-white"
                                        @click="startEditingTeamRoster(team)"
                                    >
                                        Редактировать
                                    </button>
                                </div>

                                <div class="mt-4 grid gap-2 sm:grid-cols-2">
                                    <div
                                        v-for="(role, index) in roles"
                                        :key="`${team.slug}-${role.position}`"
                                        class="rounded-xl border border-slate-800/80 bg-slate-950/70 px-3 py-2"
                                    >
                                        <div class="text-[11px] font-semibold tracking-[0.18em] text-slate-500 uppercase">
                                            {{ role.label }}
                                        </div>
                                        <div class="mt-1 truncate text-sm text-slate-200">
                                            {{ getSavedTeamPlayerDisplayName(team.players[index] ?? null) }}
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-4 flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        class="rounded-xl border border-emerald-400/30 bg-emerald-500/10 px-3 py-2 text-xs font-semibold text-emerald-100 transition hover:border-emerald-300/50 hover:bg-emerald-500/15"
                                        @click="useSavedTeamInHeroForm('radiant', team.slug)"
                                    >
                                        В Radiant
                                    </button>

                                    <button
                                        type="button"
                                        class="rounded-xl border border-rose-400/30 bg-rose-500/10 px-3 py-2 text-xs font-semibold text-rose-100 transition hover:border-rose-300/50 hover:bg-rose-500/15"
                                        @click="useSavedTeamInHeroForm('dire', team.slug)"
                                    >
                                        В Dire
                                    </button>
                                </div>
                            </article>
                        </div>
                    </section>

                    <section class="rounded-2xl border border-amber-500/20 bg-slate-950/70 p-5">
                        <div class="mb-4 space-y-2">
                            <p class="text-xs font-semibold tracking-[0.3em] text-amber-300 uppercase">
                                Editor
                            </p>
                            <h3 class="text-lg font-semibold text-white">{{ teamEditorTitle }}</h3>
                            <p class="max-w-2xl text-sm leading-6 text-slate-400">
                                Выбирайте игроков по ролям через Liquipedia. Пустые слоты можно сохранить, если состав еще не подтвержден полностью.
                            </p>
                        </div>

                        <form class="space-y-5" @submit.prevent="saveTeamRoster">
                            <label class="flex flex-col gap-2 text-sm text-slate-200">
                                Название команды
                                <input
                                    v-model="teamEditor.name"
                                    type="text"
                                    required
                                    class="rounded-xl border border-slate-700 bg-slate-900 px-4 py-3 text-sm text-slate-100 outline-none transition placeholder:text-slate-500 focus:border-amber-400"
                                    placeholder="OG"
                                />
                            </label>

                            <div class="grid gap-4 md:grid-cols-2">
                                <div
                                    v-for="role in roles"
                                    :key="`team-editor-${role.position}`"
                                    class="relative space-y-2"
                                    data-team-player-picker
                                >
                                    <div class="flex items-center justify-between gap-3">
                                        <span class="font-medium text-amber-100">{{ role.label }}</span>
                                        <span class="text-[11px] text-slate-500">Liquipedia PRO</span>
                                    </div>

                                    <div class="rounded-2xl border border-slate-700/80 bg-slate-900/80 px-3 py-3 transition focus-within:border-amber-400/60">
                                        <div class="flex items-center gap-3">
                                            <div class="min-w-0 flex-1">
                                                <input
                                                    :value="getTeamEditorPlayerValue(role.position - 1)"
                                                    :data-team-player-input="role.position - 1"
                                                    type="text"
                                                    autocomplete="off"
                                                    class="w-full bg-transparent text-sm text-slate-100 outline-none placeholder:text-slate-500"
                                                    :placeholder="`Игрок для роли ${role.label}`"
                                                    @focus="handleTeamEditorPlayerFocus(role.position - 1)"
                                                    @input="handleTeamEditorPlayerInput(role.position - 1, $event)"
                                                    @keydown.escape="closeTeamEditorPlayerPicker"
                                                />
                                            </div>

                                            <button
                                                v-if="getTeamEditorPlayerValue(role.position - 1)"
                                                type="button"
                                                tabindex="-1"
                                                class="rounded-lg border border-slate-700 px-2 py-1 text-xs font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white"
                                                @click="clearTeamEditorPlayerSelection(role.position - 1)"
                                            >
                                                ×
                                            </button>
                                        </div>

                                        <p
                                            class="mt-2 text-[11px] leading-5"
                                            :class="getTeamEditorPlayerHintClass(role.position - 1)"
                                        >
                                            {{ getTeamEditorPlayerHint(role.position - 1) }}
                                        </p>
                                    </div>

                                    <div
                                        v-if="getTeamEditorSelectedPlayer(role.position - 1)"
                                        class="rounded-2xl border border-amber-500/20 bg-amber-500/8 px-3 py-3 text-xs text-slate-200"
                                    >
                                        <div class="font-semibold text-amber-100">
                                            {{ getPlayerDisplayName(getTeamEditorSelectedPlayer(role.position - 1)!) }}
                                        </div>
                                        <div class="mt-1 text-slate-300">
                                            {{ getPlayerMetaLine(getTeamEditorSelectedPlayer(role.position - 1)!) }}
                                        </div>
                                        <div
                                            v-if="getTeamEditorSelectedPlayer(role.position - 1)!.aliases.length > 0"
                                            class="mt-1 text-slate-400"
                                        >
                                            Aliases: {{ getTeamEditorSelectedPlayer(role.position - 1)!.aliases.join(', ') }}
                                        </div>
                                    </div>

                                    <div
                                        v-if="shouldShowTeamEditorPlayerPicker(role.position - 1)"
                                        class="absolute inset-x-0 top-full z-30 mt-2 overflow-hidden rounded-2xl border border-slate-700 bg-slate-950/98 shadow-2xl shadow-slate-950/70"
                                    >
                                        <div class="border-b border-slate-800 px-3 py-2 text-[11px] font-semibold tracking-[0.22em] text-slate-400 uppercase">
                                            Кандидаты Liquipedia
                                        </div>

                                        <div
                                            v-if="getTeamEditorPlayerSlotState(role.position - 1).status === 'searching'"
                                            class="px-3 py-4 text-sm text-slate-400"
                                        >
                                            Ищем про-игроков...
                                        </div>

                                        <div
                                            v-else-if="getTeamEditorPlayerSlotState(role.position - 1).status === 'error'"
                                            class="px-3 py-4 text-sm text-rose-300"
                                        >
                                            {{ getTeamEditorPlayerSlotState(role.position - 1).error }}
                                        </div>

                                        <div
                                            v-else-if="getTeamEditorPlayerSlotState(role.position - 1).candidates.length === 0"
                                            class="px-3 py-4 text-sm text-slate-500"
                                        >
                                            Ничего не найдено. Попробуйте другой ник или alias.
                                        </div>

                                        <div v-else class="max-h-72 overflow-y-auto p-2">
                                            <button
                                                v-for="player in getTeamEditorPlayerSlotState(role.position - 1).candidates"
                                                :key="`team-editor-player-${role.position}-${player.steam_account_id}`"
                                                type="button"
                                                class="flex w-full flex-col gap-1 rounded-xl px-3 py-2.5 text-left transition hover:bg-slate-900 hover:text-white"
                                                @mousedown.prevent="selectTeamEditorPlayer(role.position - 1, player)"
                                            >
                                                <div class="font-semibold text-white">
                                                    {{ getPlayerDisplayName(player) }}
                                                </div>
                                                <div class="text-xs text-slate-300">
                                                    {{ getPlayerMetaLine(player) }}
                                                </div>
                                                <div v-if="player.aliases.length > 0" class="text-[11px] text-slate-500">
                                                    Aliases: {{ player.aliases.join(', ') }}
                                                </div>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="flex flex-wrap gap-3">
                                <button
                                    type="submit"
                                    class="rounded-xl bg-amber-400 px-4 py-3 text-sm font-semibold text-slate-950 transition hover:bg-amber-300 disabled:cursor-not-allowed disabled:opacity-60"
                                    :disabled="isLoading('team-roster-save')"
                                >
                                    {{ isLoading('team-roster-save') ? 'Сохраняем...' : teamEditorSubmitLabel }}
                                </button>

                                <button
                                    type="button"
                                    class="rounded-xl border border-slate-700 px-4 py-3 text-sm font-semibold text-slate-200 transition hover:border-slate-500 hover:text-white"
                                    @click="startCreatingTeamRoster"
                                >
                                    Очистить
                                </button>

                                <button
                                    v-if="teamEditor.slug"
                                    type="button"
                                    class="rounded-xl border border-rose-400/30 bg-rose-500/10 px-4 py-3 text-sm font-semibold text-rose-100 transition hover:border-rose-300/50 hover:bg-rose-500/15 disabled:cursor-not-allowed disabled:opacity-60"
                                    :disabled="isLoading('team-roster-delete')"
                                    @click="deleteTeamRoster"
                                >
                                    {{ isLoading('team-roster-delete') ? 'Удаляем...' : 'Удалить состав' }}
                                </button>
                            </div>
                        </form>
                    </section>
                </div>
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
                        v-if="hasRoshPlayerBreakdown"
                        class="rounded-2xl border border-emerald-400/20 bg-slate-950/70 p-4"
                    >
                        <div class="mb-4">
                            <p class="text-xs font-semibold tracking-[0.3em] text-emerald-200 uppercase">
                                Players
                            </p>
                            <h3 class="text-base font-semibold text-white">
                                Pro-player contribution
                            </h3>
                            <p class="mt-1 text-xs leading-5 text-slate-400">
                                Этот блок показывает, как playerHeroHighlight из STRATZ повлиял на итоговый ROSH прогноз
                                для hero-based режима.
                            </p>
                        </div>

                        <div
                            v-if="roshPlayerAnalysis"
                            class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5"
                        >
                            <div class="rounded-2xl border border-slate-800 bg-slate-900/80 p-3">
                                <div class="text-[11px] font-semibold tracking-[0.22em] text-slate-500 uppercase">
                                    Mode
                                </div>
                                <div class="mt-2 text-sm font-semibold text-white">
                                    {{ roshPlayerAnalysis.enabled ? 'Player-aware' : 'Hero-only' }}
                                </div>
                            </div>
                            <div class="rounded-2xl border border-slate-800 bg-slate-900/80 p-3">
                                <div class="text-[11px] font-semibold tracking-[0.22em] text-slate-500 uppercase">
                                    Source
                                </div>
                                <div class="mt-2 text-sm font-semibold text-white">
                                    {{ roshPlayerAnalysis.source }}
                                </div>
                            </div>
                            <div class="rounded-2xl border border-slate-800 bg-slate-900/80 p-3">
                                <div class="text-[11px] font-semibold tracking-[0.22em] text-slate-500 uppercase">
                                    Net shift
                                </div>
                                <div
                                    class="mt-2 text-sm font-semibold"
                                    :class="roshPlayerAnalysis.net_adjustment >= 0 ? 'text-emerald-300' : 'text-rose-300'"
                                >
                                    {{ formatSignedPercentValue(roshPlayerAnalysis.net_adjustment) }}
                                </div>
                            </div>
                            <div class="rounded-2xl border border-slate-800 bg-slate-900/80 p-3">
                                <div class="text-[11px] font-semibold tracking-[0.22em] text-slate-500 uppercase">
                                    Resolved
                                </div>
                                <div class="mt-2 text-sm font-semibold text-white">
                                    {{ roshPlayerAnalysis.resolved_count }} / {{ roshPlayerAnalysis.selected_count }}
                                </div>
                            </div>
                            <div class="rounded-2xl border border-slate-800 bg-slate-900/80 p-3">
                                <div class="text-[11px] font-semibold tracking-[0.22em] text-slate-500 uppercase">
                                    Fallbacks
                                </div>
                                <div class="mt-2 text-sm font-semibold text-white">
                                    {{ roshPlayerAnalysis.fallback_count }}
                                </div>
                            </div>
                        </div>

                        <div
                            v-if="roshPlayerAnalysis?.request_error"
                            class="mt-3 rounded-2xl border border-rose-500/20 bg-rose-500/8 px-4 py-3 text-sm text-rose-200"
                        >
                            {{ roshPlayerAnalysis.request_error }}
                        </div>

                        <div
                            v-if="roshPlayerSlots.length > 0"
                            class="mt-4 grid gap-3 xl:grid-cols-2"
                        >
                            <article
                                v-for="slot in roshPlayerSlots"
                                :key="`${slot.side}-${slot.positionId}-${slot.steamAccountId}`"
                                class="rounded-2xl border border-slate-800 bg-slate-900/80 p-4"
                            >
                                <div class="flex items-start gap-4">
                                    <div
                                        class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-2xl border"
                                        :class="slot.side === 'radiant' ? 'border-emerald-400/30 bg-slate-950' : 'border-rose-400/30 bg-slate-950'"
                                    >
                                        <img
                                            v-if="slot.hero"
                                            :src="slot.hero.image"
                                            :alt="slot.hero.title"
                                            class="h-full w-full object-cover"
                                        />
                                        <span v-else class="text-xs font-semibold text-slate-300">
                                            {{ slot.positionId ?? '—' }}
                                        </span>
                                    </div>

                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span
                                                class="rounded-full border px-2 py-0.5 text-[10px] font-semibold tracking-[0.22em] uppercase"
                                                :class="
                                                    slot.side === 'radiant'
                                                        ? 'border-emerald-400/25 bg-emerald-500/10 text-emerald-200'
                                                        : 'border-rose-400/25 bg-rose-500/10 text-rose-200'
                                                "
                                            >
                                                {{ slot.side }}
                                            </span>
                                            <span class="text-[11px] text-slate-500">
                                                {{ slot.roleLabel }}
                                            </span>
                                            <span class="text-[11px] text-slate-500">
                                                {{ slot.hero?.title ?? `Hero #${slot.heroId}` }}
                                            </span>
                                        </div>

                                        <div class="mt-2 text-sm font-semibold text-white">
                                            {{ slot.displayName }}
                                        </div>
                                        <div class="mt-1 text-xs text-slate-400">
                                            {{ slot.teamName || 'Без команды' }} · {{ formatPlayerVisibility(slot) }}
                                        </div>

                                        <div class="mt-3 flex flex-wrap items-center gap-2">
                                            <span
                                                class="rounded-full border px-2.5 py-1 text-xs font-semibold"
                                                :class="slot.impact >= 0 ? 'border-emerald-400/25 bg-emerald-500/10 text-emerald-200' : 'border-rose-400/25 bg-rose-500/10 text-rose-200'"
                                            >
                                                Impact {{ formatSignedPercentValue(slot.impact) }}
                                            </span>
                                            <span class="rounded-full border border-slate-700 bg-slate-950 px-2.5 py-1 text-xs text-slate-300">
                                                ID {{ slot.steamAccountId ?? '—' }}
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <div
                                    v-if="slot.stats"
                                    class="mt-4 grid gap-3 sm:grid-cols-3"
                                >
                                    <div class="rounded-xl border border-slate-800 bg-slate-950/80 p-3">
                                        <div class="text-[11px] font-semibold tracking-[0.22em] text-slate-500 uppercase">
                                            {{ formatPlayerWindowLabel(slot.stats.recentWindow) }}
                                        </div>
                                        <div class="mt-2 text-sm font-semibold text-white">
                                            {{ formatPercentValue(slot.stats.recentWinRate) }}
                                        </div>
                                        <div class="mt-1 text-xs text-slate-400">
                                            {{ slot.stats.recentWinCount }} / {{ slot.stats.recentMatchCount }} игр
                                        </div>
                                    </div>
                                    <div class="rounded-xl border border-slate-800 bg-slate-950/80 p-3">
                                        <div class="text-[11px] font-semibold tracking-[0.22em] text-slate-500 uppercase">
                                            All time
                                        </div>
                                        <div class="mt-2 text-sm font-semibold text-white">
                                            {{ formatPercentValue(slot.stats.winRate) }}
                                        </div>
                                        <div class="mt-1 text-xs text-slate-400">
                                            {{ slot.stats.winCount }} / {{ slot.stats.matchCount }} игр
                                        </div>
                                    </div>
                                    <div class="rounded-xl border border-slate-800 bg-slate-950/80 p-3">
                                        <div class="text-[11px] font-semibold tracking-[0.22em] text-slate-500 uppercase">
                                            IMP
                                        </div>
                                        <div class="mt-2 text-sm font-semibold text-white">
                                            {{ slot.stats.recentImp ?? slot.stats.impAllTime ?? '—' }}
                                        </div>
                                        <div class="mt-1 text-xs text-slate-400">
                                            Last played {{ formatUnixDate(slot.stats.lastPlayed ?? NaN) }}
                                        </div>
                                    </div>
                                </div>

                                <div
                                    v-else
                                    class="mt-4 rounded-xl border border-amber-400/20 bg-amber-500/8 px-3 py-3 text-sm text-amber-100"
                                >
                                    <div>{{ formatPlayerFallbackReason(slot.fallbackReason) }}</div>
                                    <div
                                        v-if="slot.fallbackMessage"
                                        class="mt-2 text-xs leading-5 text-amber-200/80"
                                    >
                                        {{ slot.fallbackMessage }}
                                    </div>
                                </div>
                            </article>
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
                            <table class="w-full min-w-[1500px] text-sm">
                                <thead class="bg-slate-900/90 text-slate-200">
                                    <tr>
                                        <th class="px-3 py-2 text-left">Minute</th>
                                        <th class="px-3 py-2 text-left">Window</th>
                                        <th class="px-3 py-2 text-left">Side</th>
                                        <th class="px-3 py-2 text-left">Radiant advantage</th>
                                        <th class="px-3 py-2 text-left">Dire advantage</th>
                                        <th class="px-3 py-2 text-left">Match %</th>
                                        <th class="px-3 py-2 text-left">Hero adj.</th>
                                        <th class="px-3 py-2 text-left">Synergy adj.</th>
                                        <th class="px-3 py-2 text-left">Player adj.</th>
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
                                                row.hero_adjustment > 0
                                                    ? 'text-emerald-300'
                                                    : row.hero_adjustment < 0
                                                      ? 'text-rose-300'
                                                      : 'text-slate-300'
                                            "
                                        >
                                            {{ formatSignedPercentValue(row.hero_adjustment) }}
                                        </td>
                                        <td
                                            class="px-3 py-3 font-semibold"
                                            :class="
                                                row.synergy_adjustment > 0
                                                    ? 'text-emerald-300'
                                                    : row.synergy_adjustment < 0
                                                      ? 'text-rose-300'
                                                      : 'text-slate-300'
                                            "
                                        >
                                            {{ formatSignedPercentValue(row.synergy_adjustment) }}
                                        </td>
                                        <td
                                            class="px-3 py-3 font-semibold"
                                            :class="
                                                row.player_adjustment > 0
                                                    ? 'text-emerald-300'
                                                    : row.player_adjustment < 0
                                                      ? 'text-rose-300'
                                                      : 'text-slate-300'
                                            "
                                        >
                                            {{ formatSignedPercentValue(row.player_adjustment) }}
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
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';

import {
    destroyTeamRoster as destroyTeamRosterAction,
    rosh as roshAction,
    roshHeroes as roshHeroesAction,
    searchProPlayers as searchProPlayersAction,
    storeTeamRoster as storeTeamRosterAction,
    updateTeamRoster as updateTeamRosterAction,
} from '@/actions/App/Http/Controllers/StratzController';
import { getHeroSearchAliases } from '@/lib/hero-aliases';

type HeroOption = {
    id: number;
    name: string;
    title: string;
    image: string;
};

type ProPlayerCandidate = {
    steam_account_id: number;
    name: string;
    is_anonymous: boolean;
    is_stratz_public: boolean;
    last_match_date_time: number | null;
    season_rank: number | null;
    season_leaderboard_rank: number | null;
    pro_name: string | null;
    aliases: string[];
    team: {
        id: number | null;
        name: string;
    } | null;
};

type SavedTeamPlayer = {
    steam_account_id: number | null;
    name: string | null;
    pro_name: string | null;
    is_anonymous: boolean | null;
    is_stratz_public: boolean | null;
    team_name: string | null;
} | null;

type SavedTeamRoster = {
    slug: string;
    name: string;
    players: SavedTeamPlayer[];
    updated_at: string;
};

type RoshPlayerPayload = {
    steam_account_id: number;
    name: string;
    pro_name: string | null;
    is_anonymous: boolean;
    is_stratz_public: boolean;
    team_name: string | null;
} | null;

type SearchableHeroOption = {
    hero: HeroOption;
    searchableTitle: string;
    searchableName: string;
    searchableId: string;
    searchableAliases: string[];
};

type HeroSide = 'radiant' | 'dire';
type HeroPickerKey = `${HeroSide}-${number}`;
type PlayerPickerKey = `${HeroSide}-${number}`;
type StratzTab = 'matchId' | 'heroes' | 'teams';
type PlayerSearchStatus = 'idle' | 'searching' | 'ready' | 'empty' | 'error';

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
    hero_adjustment: number;
    synergy_adjustment: number;
    player_adjustment: number;
};

type RoshGoogleSheetsResult = {
    spreadsheet_id: string;
    sheet_title: string;
    row: number;
    cells: Record<string, string>;
};

type RoshPlayerHeroStats = {
    lastPlayed: number | null;
    matchCount: number;
    winCount: number;
    winRate: number | null;
    impAllTime: number | null;
    lastMonth: {
        matchCount: number;
        winCount: number;
        winRate: number | null;
        imp: number | null;
    };
    lastSixMonths: {
        matchCount: number;
        winCount: number;
        winRate: number | null;
        imp: number | null;
    };
    recentWindow: 'last_month' | 'last_six_months' | 'all_time';
    recentMatchCount: number;
    recentWinCount: number;
    recentWinRate: number | null;
    recentImp: number | null;
};

type RoshPlayerAnalysisSummary = {
    enabled: boolean;
    source: string;
    selected_count: number;
    resolved_count: number;
    fallback_count: number;
    radiant_total_impact: number;
    dire_total_impact: number;
    net_adjustment: number;
    request_error: string | null;
};

type RoshMatchPlayer = {
    heroId: number;
    position: string;
    isRadiant: boolean;
    steamAccountId?: number | null;
    playerName?: string | null;
    proName?: string | null;
    teamName?: string | null;
    isAnonymous?: boolean | null;
    isStratzPublic?: boolean | null;
    playerHeroStats?: RoshPlayerHeroStats | null;
    playerImpact?: number;
    playerFallbackReason?: string | null;
    playerFallbackMessage?: string | null;
};

type RoshRawPayload = {
    match?: {
        considerPlayers?: boolean;
        players?: RoshMatchPlayer[];
    };
    analysis_summary?: {
        player_hero_highlights?: RoshPlayerAnalysisSummary;
    };
};

type RoshPlayerSlotSummary = {
    side: HeroSide;
    heroId: number;
    hero: HeroOption | null;
    positionId: number | null;
    roleLabel: string;
    displayName: string;
    teamName: string | null;
    steamAccountId: number | null;
    isStratzPublic: boolean | null;
    isAnonymous: boolean | null;
    impact: number;
    fallbackReason: string | null;
    fallbackMessage: string | null;
    stats: RoshPlayerHeroStats | null;
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

type ApiEnvelope<TData> = {
    type?: string;
    data?: TData;
    error?: string;
};

type PlayerSlotState = {
    selected: ProPlayerCandidate | null;
    candidates: ProPlayerCandidate[];
    status: PlayerSearchStatus;
    error: string;
    debounceTimer: number | null;
    searchToken: number;
};

type TeamRosterFormState = {
    slug: string | null;
    name: string;
    playerQueries: string[];
    selectedPlayers: Array<ProPlayerCandidate | null>;
};

const props = defineProps<{
    heroes: HeroOption[];
    savedTeams: SavedTeamRoster[];
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
    {
        id: 'teams',
        label: 'Команды',
        shortLabel: 'Teams',
        description: 'Сохраненные составы для быстрой подстановки про-игроков в hero-based ROSH.',
        activeClasses: 'border-amber-400/50 bg-amber-500/10 text-amber-50',
        badgeClasses: 'border-amber-300/40 bg-amber-300/10 text-amber-100',
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
    considerPlayers: false,
    radiantHeroes: Array.from({ length: 5 }, () => ''),
    direHeroes: Array.from({ length: 5 }, () => ''),
    radiantPlayers: Array.from({ length: 5 }, () => ''),
    direPlayers: Array.from({ length: 5 }, () => ''),
});

const heroTeamPresets = reactive<Record<HeroSide, string>>({
    radiant: '',
    dire: '',
});

const openHeroPickerKey = ref<HeroPickerKey | null>(null);
const openPlayerPickerKey = ref<PlayerPickerKey | null>(null);
const activeHeroOptionIndex = ref(0);
const loadingAction = ref<string | null>(null);
const errorMessage = ref('');
const result = ref<StratzResult | null>(null);
const proPlayerSearchCache = new Map<string, ProPlayerCandidate[]>();
const savedTeams = ref<SavedTeamRoster[]>(props.savedTeams);

const createPlayerSlotState = (): PlayerSlotState => ({
    selected: null,
    candidates: [],
    status: 'idle',
    error: '',
    debounceTimer: null,
    searchToken: 0,
});

const playerSearchState = reactive<Record<HeroSide, PlayerSlotState[]>>({
    radiant: Array.from({ length: 5 }, () => createPlayerSlotState()),
    dire: Array.from({ length: 5 }, () => createPlayerSlotState()),
});

const teamEditor = reactive<TeamRosterFormState>({
    slug: null,
    name: '',
    playerQueries: Array.from({ length: 5 }, () => ''),
    selectedPlayers: Array.from({ length: 5 }, () => null),
});

const teamEditorPlayerSearchState = reactive<PlayerSlotState[]>(
    Array.from({ length: 5 }, () => createPlayerSlotState()),
);

const openTeamEditorPlayerPickerIndex = ref<number | null>(null);

const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';

const normalizeSavedTeamPlayer = (player: SavedTeamPlayer): SavedTeamPlayer => {
    if (! player) {
        return null;
    }

    return {
        steam_account_id: typeof player.steam_account_id === 'number' ? player.steam_account_id : null,
        name: player.name ?? null,
        pro_name: player.pro_name ?? null,
        is_anonymous: typeof player.is_anonymous === 'boolean' ? player.is_anonymous : null,
        is_stratz_public: typeof player.is_stratz_public === 'boolean' ? player.is_stratz_public : null,
        team_name: player.team_name ?? null,
    };
};

const normalizeSavedTeamRoster = (team: SavedTeamRoster): SavedTeamRoster => ({
    slug: team.slug,
    name: team.name,
    players: Array.from({ length: roles.length }, (_, index) => normalizeSavedTeamPlayer(team.players[index] ?? null)),
    updated_at: team.updated_at,
});

const sortSavedTeamRosters = (teams: SavedTeamRoster[]): SavedTeamRoster[] =>
    [...teams]
        .map(normalizeSavedTeamRoster)
        .sort((left, right) => left.name.localeCompare(right.name, 'ru'));

savedTeams.value = sortSavedTeamRosters(savedTeams.value);

const savedTeamsSorted = computed<SavedTeamRoster[]>(() => sortSavedTeamRosters(savedTeams.value));

const hydrateProPlayerCandidate = (player: SavedTeamPlayer): ProPlayerCandidate | null => {
    if (! player || player.steam_account_id === null) {
        return null;
    }

    return {
        steam_account_id: player.steam_account_id,
        name: player.name ?? `#${player.steam_account_id}`,
        is_anonymous: player.is_anonymous ?? false,
        is_stratz_public: player.is_stratz_public ?? false,
        last_match_date_time: null,
        season_rank: null,
        season_leaderboard_rank: null,
        pro_name: player.pro_name ?? null,
        aliases: [],
        team: player.team_name
            ? {
                  id: null,
                  name: player.team_name,
              }
            : null,
    };
};

const findSavedTeamRoster = (slug: string): SavedTeamRoster | null =>
    savedTeams.value.find((team) => team.slug === slug) ?? null;

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

const heroesById = computed(() => new Map(props.heroes.map((hero) => [hero.id, hero])));

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

const roshRawPayload = computed<RoshRawPayload | null>(() => {
    if (result.value?.type !== 'rosh') {
        return null;
    }

    return ((result.value.data as RoshResultPayload)?.raw as RoshRawPayload | undefined) ?? null;
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

const roshPlayerAnalysis = computed<RoshPlayerAnalysisSummary | null>(() =>
    roshRawPayload.value?.analysis_summary?.player_hero_highlights ?? null,
);

const roshPlayerSlots = computed<RoshPlayerSlotSummary[]>(() => {
    if (! roshRawPayload.value?.match?.considerPlayers) {
        return [];
    }

    const players = roshRawPayload.value?.match?.players ?? [];

    return players
        .filter((player) => player.steamAccountId != null)
        .map((player) => {
            const positionId = extractPositionId(player.position);
            const hero = heroesById.value.get(player.heroId) ?? null;
            const displayName = player.proName || player.playerName || `#${player.steamAccountId}`;

            return {
                side: player.isRadiant ? 'radiant' : 'dire',
                heroId: player.heroId,
                hero,
                positionId,
                roleLabel: getRoleLabel(positionId),
                displayName,
                teamName: player.teamName ?? null,
                steamAccountId: player.steamAccountId ?? null,
                isStratzPublic: player.isStratzPublic ?? null,
                isAnonymous: player.isAnonymous ?? null,
                impact: player.playerImpact ?? 0,
                fallbackReason: player.playerFallbackReason ?? null,
                fallbackMessage: player.playerFallbackMessage ?? null,
                stats: player.playerHeroStats ?? null,
            };
        });
});

const hasRoshPlayerBreakdown = computed(() =>
    (roshRawPayload.value?.match?.considerPlayers ?? false)
    && ((roshPlayerAnalysis.value?.enabled ?? false) || roshPlayerSlots.value.length > 0),
);

const isLoading = (action: string): boolean => loadingAction.value === action;

const jsonHeaders = () => ({
    'Content-Type': 'application/json',
    Accept: 'application/json',
    'X-CSRF-Token': csrfToken,
});

const postJson = async <TResponse>(route: RouteTarget, payload: unknown): Promise<TResponse> => {
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

    return body as TResponse;
};

const normalizeHeroQuery = (value: string): string =>
    value
        .trim()
        .toLowerCase()
        .replace(/[^\p{L}\p{N}]+/gu, ' ')
        .replace(/\s+/g, ' ');

const normalizePlayerQuery = (value: string): string =>
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

const playerPickerKey = (side: HeroSide, index: number): PlayerPickerKey => `${side}-${index}` as PlayerPickerKey;

const getPlayerValue = (side: HeroSide, index: number): string =>
    side === 'radiant' ? heroForm.radiantPlayers[index] : heroForm.direPlayers[index];

const setPlayerValue = (side: HeroSide, index: number, value: string): void => {
    if (side === 'radiant') {
        heroForm.radiantPlayers[index] = value;
        return;
    }

    heroForm.direPlayers[index] = value;
};

const getPlayerSlotState = (side: HeroSide, index: number): PlayerSlotState => playerSearchState[side][index];

const setHeroPlayerSelection = (side: HeroSide, index: number, player: ProPlayerCandidate | null): void => {
    const slot = getPlayerSlotState(side, index);

    clearPlayerSearchTimer(slot);
    slot.selected = player;
    slot.candidates = [];
    slot.status = player ? 'ready' : 'idle';
    slot.error = '';
    slot.searchToken += 1;
    setPlayerValue(side, index, player ? getPlayerDisplayName(player) : '');
};

const openPlayerPicker = (side: HeroSide, index: number): void => {
    closeHeroPicker();
    openPlayerPickerKey.value = playerPickerKey(side, index);
};

const closePlayerPicker = (): void => {
    openPlayerPickerKey.value = null;
};

const isPlayerPickerOpen = (side: HeroSide, index: number): boolean =>
    openPlayerPickerKey.value === playerPickerKey(side, index);

const clearPlayerSearchTimer = (slot: PlayerSlotState): void => {
    if (slot.debounceTimer !== null) {
        window.clearTimeout(slot.debounceTimer);
        slot.debounceTimer = null;
    }
};

const getPlayerDisplayName = (player: ProPlayerCandidate): string => player.pro_name ?? player.name;

const getPlayerMetaLine = (player: ProPlayerCandidate): string => {
    const parts = [
        player.name !== '' && player.name !== getPlayerDisplayName(player) ? player.name : null,
        player.team?.name ?? null,
        player.season_leaderboard_rank !== null ? `#${player.season_leaderboard_rank}` : null,
    ].filter((value): value is string => typeof value === 'string' && value !== '');

    return parts.join(' / ') || 'Без дополнительной мета-информации';
};

const selectedPlayerFor = (side: HeroSide, index: number): ProPlayerCandidate | null =>
    getPlayerSlotState(side, index).selected;

const getPlayerMatches = (side: HeroSide, index: number): ProPlayerCandidate[] =>
    getPlayerSlotState(side, index).candidates;

const getPlayerSearchStatus = (side: HeroSide, index: number): PlayerSearchStatus =>
    getPlayerSlotState(side, index).status;

const getPlayerSearchError = (side: HeroSide, index: number): string =>
    getPlayerSlotState(side, index).error;

const shouldShowPlayerPicker = (side: HeroSide, index: number): boolean => {
    if (! heroForm.considerPlayers || ! isPlayerPickerOpen(side, index)) {
        return false;
    }

    const slot = getPlayerSlotState(side, index);

    if (slot.status === 'searching' || slot.status === 'error' || slot.candidates.length > 0) {
        return true;
    }

    if (slot.selected) {
        return false;
    }

    return normalizePlayerQuery(getPlayerValue(side, index)).length >= 2;
};

const getPlayerHint = (side: HeroSide, index: number): string => {
    const slot = getPlayerSlotState(side, index);
    const query = normalizePlayerQuery(getPlayerValue(side, index));

    if (slot.selected) {
        return `Выбран ${getPlayerDisplayName(slot.selected)}.`;
    }

    if (slot.status === 'searching') {
        return 'Ищем только среди pro-игроков Liquipedia...';
    }

    if (slot.status === 'error') {
        return slot.error;
    }

    if (slot.status === 'empty' && query.length >= 2) {
        return 'Совпадений не найдено. Попробуйте другой ник или alias.';
    }

    if (query !== '' && query.length < 2) {
        return 'Для поиска нужно минимум 2 символа.';
    }

    return 'Поиск ограничен только про-игроками Liquipedia.';
};

const getPlayerHintClass = (side: HeroSide, index: number): string => {
    const slot = getPlayerSlotState(side, index);

    if (slot.status === 'error') {
        return 'text-rose-300';
    }

    if (slot.selected) {
        return side === 'radiant' ? 'text-emerald-300' : 'text-rose-300';
    }

    return 'text-slate-500';
};

const focusNextPlayerInput = (side: HeroSide, index: number): void => {
    requestAnimationFrame(() => {
        const currentKey = playerPickerKey(side, index);
        const inputs = Array.from(document.querySelectorAll<HTMLInputElement>('[data-player-input]'));
        const currentInputIndex = inputs.findIndex((input) => input.dataset.playerInput === currentKey);

        if (currentInputIndex < 0) {
            return;
        }

        const nextInput = inputs[currentInputIndex + 1] ?? inputs[currentInputIndex];

        nextInput?.focus();
    });
};

const fetchProPlayerCandidates = async (query: string): Promise<ProPlayerCandidate[]> => {
    const normalizedQuery = normalizePlayerQuery(query);
    const cachedPlayers = proPlayerSearchCache.get(normalizedQuery);

    if (cachedPlayers) {
        return cachedPlayers;
    }

    const response = await postJson<ApiEnvelope<ProPlayerCandidate[]>>(searchProPlayersAction.post(), {
        query: query.trim(),
        take: 5,
    });
    const candidates = Array.isArray(response.data) ? response.data : [];

    proPlayerSearchCache.set(normalizedQuery, candidates);

    return candidates;
};

const getTeamEditorPlayerSlotState = (index: number): PlayerSlotState => teamEditorPlayerSearchState[index];

const getTeamEditorPlayerValue = (index: number): string => teamEditor.playerQueries[index];

const getTeamEditorSelectedPlayer = (index: number): ProPlayerCandidate | null => teamEditor.selectedPlayers[index];

const openTeamEditorPlayerPicker = (index: number): void => {
    closeHeroPicker();
    closePlayerPicker();
    openTeamEditorPlayerPickerIndex.value = index;
};

const closeTeamEditorPlayerPicker = (): void => {
    openTeamEditorPlayerPickerIndex.value = null;
};

const isTeamEditorPlayerPickerOpen = (index: number): boolean => openTeamEditorPlayerPickerIndex.value === index;

const setTeamEditorPlayerSelection = (index: number, player: ProPlayerCandidate | null): void => {
    const slot = getTeamEditorPlayerSlotState(index);

    clearPlayerSearchTimer(slot);
    teamEditor.selectedPlayers[index] = player;
    teamEditor.playerQueries[index] = player ? getPlayerDisplayName(player) : '';
    slot.selected = player;
    slot.candidates = [];
    slot.status = player ? 'ready' : 'idle';
    slot.error = '';
    slot.searchToken += 1;
};

const focusNextTeamEditorPlayerInput = (index: number): void => {
    requestAnimationFrame(() => {
        const inputs = Array.from(document.querySelectorAll<HTMLInputElement>('[data-team-player-input]'));
        const currentInputIndex = inputs.findIndex((input) => Number(input.dataset.teamPlayerInput) === index);

        if (currentInputIndex < 0) {
            return;
        }

        const nextInput = inputs[currentInputIndex + 1] ?? inputs[currentInputIndex];

        nextInput?.focus();
    });
};

const scheduleTeamEditorPlayerSearch = (index: number, value: string): void => {
    const slot = getTeamEditorPlayerSlotState(index);
    const normalizedQuery = normalizePlayerQuery(value);

    clearPlayerSearchTimer(slot);
    teamEditor.selectedPlayers[index] = null;
    slot.selected = null;
    slot.error = '';

    if (normalizedQuery.length < 2) {
        slot.candidates = [];
        slot.status = 'idle';
        return;
    }

    slot.status = 'searching';
    openTeamEditorPlayerPicker(index);
    slot.searchToken += 1;
    const currentToken = slot.searchToken;

    slot.debounceTimer = window.setTimeout(async () => {
        try {
            const candidates = await fetchProPlayerCandidates(value);

            if (slot.searchToken !== currentToken) {
                return;
            }

            slot.candidates = candidates;
            slot.status = candidates.length > 0 ? 'ready' : 'empty';
        } catch (error) {
            if (slot.searchToken !== currentToken) {
                return;
            }

            slot.candidates = [];
            slot.status = 'error';
            slot.error = error instanceof Error ? error.message : String(error);
        }
    }, 500);
};

const shouldShowTeamEditorPlayerPicker = (index: number): boolean => {
    if (! isTeamEditorPlayerPickerOpen(index)) {
        return false;
    }

    const slot = getTeamEditorPlayerSlotState(index);

    if (slot.status === 'searching' || slot.status === 'error' || slot.candidates.length > 0) {
        return true;
    }

    if (slot.selected) {
        return false;
    }

    return normalizePlayerQuery(getTeamEditorPlayerValue(index)).length >= 2;
};

const getTeamEditorPlayerHint = (index: number): string => {
    const slot = getTeamEditorPlayerSlotState(index);
    const query = normalizePlayerQuery(getTeamEditorPlayerValue(index));

    if (slot.selected) {
        return `Выбран ${getPlayerDisplayName(slot.selected)}.`;
    }

    if (slot.status === 'searching') {
        return 'Ищем про-игроков Liquipedia...';
    }

    if (slot.status === 'error') {
        return slot.error;
    }

    if (slot.status === 'empty' && query.length >= 2) {
        return 'Совпадений не найдено. Попробуйте другой ник или alias.';
    }

    if (query !== '' && query.length < 2) {
        return 'Для поиска нужно минимум 2 символа.';
    }

    return 'Поиск ограничен pro-игроками Liquipedia.';
};

const getTeamEditorPlayerHintClass = (index: number): string => {
    const slot = getTeamEditorPlayerSlotState(index);

    if (slot.status === 'error') {
        return 'text-rose-300';
    }

    if (slot.selected) {
        return 'text-amber-200';
    }

    return 'text-slate-500';
};

const handleTeamEditorPlayerFocus = (index: number): void => {
    openTeamEditorPlayerPicker(index);

    const slot = getTeamEditorPlayerSlotState(index);

    if (slot.selected || slot.status === 'searching' || slot.candidates.length > 0) {
        return;
    }

    const value = getTeamEditorPlayerValue(index);

    if (normalizePlayerQuery(value).length >= 2) {
        scheduleTeamEditorPlayerSearch(index, value);
    }
};

const handleTeamEditorPlayerInput = (index: number, event: Event): void => {
    const value = event.target instanceof HTMLInputElement ? event.target.value : '';

    teamEditor.playerQueries[index] = value;
    scheduleTeamEditorPlayerSearch(index, value);
};

const selectTeamEditorPlayer = (index: number, player: ProPlayerCandidate): void => {
    setTeamEditorPlayerSelection(index, player);
    closeTeamEditorPlayerPicker();
    focusNextTeamEditorPlayerInput(index);
};

const clearTeamEditorPlayerSelection = (index: number): void => {
    setTeamEditorPlayerSelection(index, null);
    openTeamEditorPlayerPicker(index);
};

const schedulePlayerSearch = (side: HeroSide, index: number, value: string): void => {
    const slot = getPlayerSlotState(side, index);
    const normalizedQuery = normalizePlayerQuery(value);

    clearPlayerSearchTimer(slot);
    slot.selected = null;
    slot.error = '';

    if (normalizedQuery.length < 2) {
        slot.candidates = [];
        slot.status = 'idle';
        return;
    }

    slot.status = 'searching';
    openPlayerPicker(side, index);
    slot.searchToken += 1;
    const currentToken = slot.searchToken;

    slot.debounceTimer = window.setTimeout(async () => {
        try {
            const candidates = await fetchProPlayerCandidates(value);

            if (slot.searchToken !== currentToken) {
                return;
            }

            slot.candidates = candidates;
            slot.status = candidates.length > 0 ? 'ready' : 'empty';
        } catch (error) {
            if (slot.searchToken !== currentToken) {
                return;
            }

            slot.candidates = [];
            slot.status = 'error';
            slot.error = error instanceof Error ? error.message : String(error);
        }
    }, 500);
};

const selectPlayer = (side: HeroSide, index: number, player: ProPlayerCandidate): void => {
    setHeroPlayerSelection(side, index, player);
    closePlayerPicker();
    focusNextPlayerInput(side, index);
};

const clearPlayerSelection = (side: HeroSide, index: number): void => {
    setHeroPlayerSelection(side, index, null);
    openPlayerPicker(side, index);
};

const serializeSelectedPlayer = (player: ProPlayerCandidate | null): RoshPlayerPayload => {
    if (! player) {
        return null;
    }

    return {
        steam_account_id: player.steam_account_id,
        name: player.name,
        pro_name: player.pro_name,
        is_anonymous: player.is_anonymous,
        is_stratz_public: player.is_stratz_public,
        team_name: player.team?.name ?? null,
    };
};

const buildSelectedPlayersPayload = (side: HeroSide): RoshPlayerPayload[] =>
    Array.from({ length: 5 }, (_, index) => serializeSelectedPlayer(selectedPlayerFor(side, index)));

const applySavedTeamToHeroForm = (side: HeroSide, slug: string): void => {
    const team = findSavedTeamRoster(slug);

    if (! team) {
        return;
    }

    if (side === 'radiant') {
        heroForm.radiantTeam = team.name;
    } else {
        heroForm.direTeam = team.name;
    }

    team.players.forEach((player, index) => {
        setHeroPlayerSelection(side, index, hydrateProPlayerCandidate(player));
    });

    heroTeamPresets[side] = slug;
};

const handleSavedTeamSelection = (side: HeroSide, event: Event): void => {
    const slug = event.target instanceof HTMLSelectElement ? event.target.value : '';

    heroTeamPresets[side] = slug;

    if (slug !== '') {
        applySavedTeamToHeroForm(side, slug);
    }
};

const buildTeamEditorPlayersPayload = (): RoshPlayerPayload[] =>
    Array.from({ length: roles.length }, (_, index) => serializeSelectedPlayer(getTeamEditorSelectedPlayer(index)));

const startCreatingTeamRoster = (): void => {
    teamEditor.slug = null;
    teamEditor.name = '';
    closeTeamEditorPlayerPicker();

    for (let index = 0; index < roles.length; index += 1) {
        setTeamEditorPlayerSelection(index, null);
    }
};

const startEditingTeamRoster = (team: SavedTeamRoster): void => {
    const normalizedTeam = normalizeSavedTeamRoster(team);

    teamEditor.slug = normalizedTeam.slug;
    teamEditor.name = normalizedTeam.name;
    closeTeamEditorPlayerPicker();

    normalizedTeam.players.forEach((player, index) => {
        setTeamEditorPlayerSelection(index, hydrateProPlayerCandidate(player));
    });
};

const upsertSavedTeamRoster = (team: SavedTeamRoster): void => {
    const normalizedTeam = normalizeSavedTeamRoster(team);

    savedTeams.value = sortSavedTeamRosters([
        ...savedTeams.value.filter((savedTeam) => savedTeam.slug !== normalizedTeam.slug),
        normalizedTeam,
    ]);
};

const removeSavedTeamRoster = (slug: string): void => {
    savedTeams.value = savedTeams.value.filter((team) => team.slug !== slug);

    for (const side of ['radiant', 'dire'] as const) {
        if (heroTeamPresets[side] === slug) {
            heroTeamPresets[side] = '';
        }
    }
};

const saveTeamRoster = async (): Promise<void> => {
    const trimmedName = teamEditor.name.trim();

    if (trimmedName === '') {
        errorMessage.value = 'Укажите название команды.';
        return;
    }

    loadingAction.value = 'team-roster-save';
    errorMessage.value = '';

    try {
        const route = teamEditor.slug
            ? updateTeamRosterAction.patch({ teamRoster: teamEditor.slug })
            : storeTeamRosterAction.post();

        const response = await postJson<ApiEnvelope<SavedTeamRoster>>(route, {
            name: trimmedName,
            players: buildTeamEditorPlayersPayload(),
        });

        if (! response.data) {
            throw new Error('Не удалось сохранить состав команды.');
        }

        const savedTeam = normalizeSavedTeamRoster(response.data);

        upsertSavedTeamRoster(savedTeam);
        startEditingTeamRoster(savedTeam);
    } catch (error) {
        errorMessage.value = error instanceof Error ? error.message : String(error);
    } finally {
        loadingAction.value = null;
    }
};

const deleteTeamRoster = async (): Promise<void> => {
    if (! teamEditor.slug) {
        startCreatingTeamRoster();
        return;
    }

    if (! window.confirm('Удалить сохраненный состав команды?')) {
        return;
    }

    loadingAction.value = 'team-roster-delete';
    errorMessage.value = '';

    try {
        const slug = teamEditor.slug;

        await postJson<ApiEnvelope<{ slug: string }>>(destroyTeamRosterAction.delete({ teamRoster: slug }), {});

        removeSavedTeamRoster(slug);
        startCreatingTeamRoster();
    } catch (error) {
        errorMessage.value = error instanceof Error ? error.message : String(error);
    } finally {
        loadingAction.value = null;
    }
};

const useSavedTeamInHeroForm = (side: HeroSide, slug: string): void => {
    applySavedTeamToHeroForm(side, slug);
    activeTab.value = 'heroes';
};

const countFilledSavedTeamSlots = (team: SavedTeamRoster): number =>
    team.players.filter((player) => player?.steam_account_id !== null).length;

const formatSavedTeamUpdatedAt = (value: string): string => {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return date.toLocaleString('ru-RU');
};

const getSavedTeamPlayerDisplayName = (player: SavedTeamPlayer): string => player?.pro_name ?? player?.name ?? '—';

const teamEditorTitle = computed(() => (teamEditor.slug ? 'Редактирование состава' : 'Новый состав'));

const teamEditorSubmitLabel = computed(() => (teamEditor.slug ? 'Сохранить изменения' : 'Создать состав'));

const handlePlayerFocus = (side: HeroSide, index: number): void => {
    openPlayerPicker(side, index);

    const slot = getPlayerSlotState(side, index);

    if (slot.selected || slot.status === 'searching' || slot.candidates.length > 0) {
        return;
    }

    const value = getPlayerValue(side, index);

    if (normalizePlayerQuery(value).length >= 2) {
        schedulePlayerSearch(side, index, value);
    }
};

const handlePlayerInput = (side: HeroSide, index: number, event: Event): void => {
    const value = event.target instanceof HTMLInputElement ? event.target.value : '';

    setPlayerValue(side, index, value);
    schedulePlayerSearch(side, index, value);
};

const openHeroPicker = (side: HeroSide, index: number): void => {
    closePlayerPicker();
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

const shouldHideResolvedHeroDropdown = (side: HeroSide, index: number): boolean => {
    const selectedHero = selectedHeroFor(side, index);

    if (! selectedHero) {
        return false;
    }

    const matches = getHeroMatches(side, index);

    return (
        matches.length === 1
        && matches[0].id === selectedHero.id
        && normalizeHeroQuery(getHeroValue(side, index)) === normalizeHeroQuery(selectedHero.title)
    );
};

const shouldShowHeroPicker = (side: HeroSide, index: number): boolean =>
    isHeroPickerOpen(side, index) && ! shouldHideResolvedHeroDropdown(side, index);

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

    if (target instanceof Element && target.closest('[data-hero-picker], [data-player-picker], [data-team-player-picker]')) {
        return;
    }

    closeHeroPicker();
    closePlayerPicker();
    closeTeamEditorPlayerPicker();
};

onMounted(() => {
    document.addEventListener('pointerdown', handleDocumentPointerDown);
});

watch(
    () => heroForm.considerPlayers,
    (enabled) => {
        if (! enabled) {
            closePlayerPicker();

            for (const side of ['radiant', 'dire'] as const) {
                for (const slot of playerSearchState[side]) {
                    clearPlayerSearchTimer(slot);
                }
            }
        }
    },
);

onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', handleDocumentPointerDown);

    for (const side of ['radiant', 'dire'] as const) {
        for (const slot of playerSearchState[side]) {
            clearPlayerSearchTimer(slot);
        }
    }

    for (const slot of teamEditorPlayerSearchState) {
        clearPlayerSearchTimer(slot);
    }
});

const request = async (action: string, route: RouteTarget, payload: unknown): Promise<void> => {
    loadingAction.value = action;
    errorMessage.value = '';

    try {
        const body = await postJson<ApiEnvelope<unknown>>(route, payload);

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
            consider_players: heroForm.considerPlayers,
            radiant_heroes: radiantHeroes,
            dire_heroes: direHeroes,
            ...(heroForm.considerPlayers
                ? {
                      radiant_players: buildSelectedPlayersPayload('radiant'),
                      dire_players: buildSelectedPlayersPayload('dire'),
                  }
                : {}),
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

const extractPositionId = (position: string | null | undefined): number | null => {
    if (! position) {
        return null;
    }

    const match = position.match(/POSITION_(\d+)/);

    if (! match) {
        return null;
    }

    const value = Number(match[1]);

    return Number.isInteger(value) ? value : null;
};

const getRoleLabel = (positionId: number | null): string => {
    if (positionId === null) {
        return 'Без роли';
    }

    return roles.find((role) => role.position === positionId)?.label ?? `Позиция ${positionId}`;
};

const formatPlayerWindowLabel = (value: RoshPlayerHeroStats['recentWindow']): string => {
    if (value === 'last_month') {
        return 'За месяц';
    }

    if (value === 'last_six_months') {
        return 'За 6 месяцев';
    }

    return 'За все время';
};

const formatPlayerFallbackReason = (reason: string | null | undefined): string => {
    if (! reason) {
        return 'Статистика успешно получена.';
    }

    const fallbackLabels: Record<string, string> = {
        player_not_selected: 'Игрок не выбран в этом слоте.',
        player_is_anonymous: 'STRATZ пометил аккаунт как anonymous.',
        player_stats_request_failed: 'Запрос playerHeroHighlight завершился ошибкой.',
        player_hero_stats_missing: 'STRATZ не вернул статистику игрока на этом герое.',
        hero_not_selected: 'Для игрока не выбран герой.',
    };

    return fallbackLabels[reason] ?? reason;
};

const formatPlayerVisibility = (slot: RoshPlayerSlotSummary): string => {
    if (slot.isAnonymous) {
        return 'Anonymous';
    }

    if (slot.isStratzPublic === false) {
        return 'STRATZ private';
    }

    if (slot.isStratzPublic === true) {
        return 'STRATZ public';
    }

    return 'Visibility unknown';
};
</script>
