type HeroSearchHero = {
    id: number;
    name: string;
    title: string;
};

const internetAliasesByHeroId: Record<number, string[]> = {
    1: ['am'],
    4: ['bs'],
    5: ['cm', 'rylai'],
    6: ['dr', 'drow'],
    7: ['es'],
    8: ['jug', 'jugg'],
    9: ['mir', 'potm'],
    10: ['mor', 'morph'],
    11: ['sf'],
    12: ['pl'],
    15: ['raz'],
    16: ['sk'],
    17: ['raijin', 'ss', 'sto', 'storm'],
    20: ['vs'],
    21: ['wr', 'windrunner'],
    23: ['kun'],
    27: ['rhasta', 'ss'],
    28: ['sld'],
    29: ['tide'],
    30: ['wd'],
    32: ['sa'],
    33: ['eni'],
    34: ['tk'],
    35: ['sni'],
    36: ['nec', 'necro', 'necrolyte'],
    37: ['wl'],
    38: ['beast', 'bm', 'bst'],
    39: ['qop'],
    40: ['veno'],
    41: ['fv', 'void'],
    42: ['sk', 'snk', 'wk'],
    43: ['dp', 'krobelus'],
    44: ['mortred', 'pa'],
    45: ['pug'],
    46: ['ta'],
    49: ['dk'],
    50: ['daz'],
    51: ['cg', 'clock', 'cw'],
    52: ['lesh'],
    53: ['np', 'furion'],
    54: ['life', 'ls', 'naix'],
    55: ['ds'],
    56: ['cli'],
    57: ['omni'],
    58: ['ench'],
    59: ['hus'],
    60: ['ns'],
    61: ['bm', 'bro', 'brood'],
    62: ['bh'],
    63: ['wea'],
    64: ['jak', 'thd'],
    65: ['bat', 'br'],
    67: ['spe'],
    68: ['aa'],
    69: ['db'],
    71: ['sb'],
    72: ['gyro'],
    73: ['alc'],
    74: ['inv'],
    75: ['sil'],
    76: ['od', 'destroyer', 'outworld destroyer'],
    77: ['lyc'],
    78: ['bm', 'brew', 'panda'],
    79: ['sd'],
    80: ['druid', 'ld', 'sylla'],
    81: ['ck'],
    82: ['geomancer', 'meepwn'],
    83: ['tp', 'treant', 'tree'],
    84: ['ogre', 'om'],
    85: ['dirge', 'ud'],
    86: ['rub', 'rubick'],
    87: ['dis', 'disruptor', 'thrall'],
    88: ['na', 'nyx'],
    89: ['naga'],
    90: ['ezalor', 'keeper', 'kotl'],
    91: ['io', 'wisp'],
    92: ['vis', 'visage'],
    93: ['slark', 'slk'],
    94: ['gorgon', 'med', 'medusa'],
    95: ['jahrakal', 'troll', 'tw'],
    96: ['cent', 'centaur'],
    97: ['mag', 'magnataur', 'magnus'],
    98: ['rizzrack', 'shredder', 'tim', 'timber', 'timbersaw'],
    99: ['bb', 'rigwarl'],
    100: ['tusk', 'tuskarr'],
    101: ['dragonus', 'sky', 'skywrath', 'sm'],
    102: ['aba', 'abaddon', 'loa'],
    103: ['cairne', 'et', 'tc', 'titan'],
    104: ['lc', 'legion', 'tresdin'],
    105: ['tec'],
    106: ['emb', 'ember', 'xin'],
    107: ['earth', 'esp', 'kaolin'],
    108: ['azgalor', 'pitlord', 'ul'],
    109: ['tb'],
    110: ['ph', 'phx'],
    111: ['ora'],
    112: ['ww'],
    113: ['aw', 'zet'],
    114: ['mk'],
    119: ['dw'],
    120: ['ar', 'pan'],
    121: ['grim', 'gs'],
    131: ['ringmaster'],
};

const manualRussianAliasesByHeroId: Record<number, string[]> = {
    7: ['шейкер'],
    11: ['сф', 'невермор'],
    14: ['пудж', 'падж', 'бучер', 'мясник'],
    21: ['виндра'],
    39: ['квоп', 'квопа'],
    42: ['вк', 'скелет', 'скелетон кинг'],
    43: ['кробелус'],
    44: ['па', 'мортра'],
    53: ['нп', 'фура', 'фурион'],
    54: ['найкс'],
    71: ['сб', 'бара', 'баратрум'],
    76: ['од'],
    83: ['трент', 'треант', 'дерево'],
    87: ['тралл'],
    90: ['котл', 'котёл', 'езалор'],
    91: ['ио', 'висп'],
    98: ['тимбер', 'шреддер'],
    101: ['скай'],
    104: ['лега', 'легионка'],
    106: ['эмбер'],
    107: ['каолин'],
    109: ['тб'],
};

const latinToCyrillicWordReplacements = [
    ['shch', 'щ'],
    ['sch', 'щ'],
    ['dge', 'дж'],
    ['zh', 'ж'],
    ['kh', 'х'],
    ['ts', 'ц'],
    ['ch', 'ч'],
    ['sh', 'ш'],
    ['yo', 'ё'],
    ['yu', 'ю'],
    ['ya', 'я'],
    ['ye', 'е'],
    ['ph', 'ф'],
    ['qu', 'кв'],
    ['ck', 'к'],
] as const;

const latinToCyrillicChars: Record<string, string> = {
    a: 'а',
    b: 'б',
    c: 'к',
    d: 'д',
    e: 'е',
    f: 'ф',
    g: 'г',
    h: 'х',
    i: 'и',
    j: 'дж',
    k: 'к',
    l: 'л',
    m: 'м',
    n: 'н',
    o: 'о',
    p: 'п',
    q: 'кв',
    r: 'р',
    s: 'с',
    t: 'т',
    u: 'у',
    v: 'в',
    w: 'в',
    x: 'кс',
    y: 'й',
    z: 'з',
};

const transliterateAliasToCyrillic = (value: string): string => {
    let normalized = value
        .toLowerCase()
        .replace(/['’]/g, '')
        .replace(/[^a-z0-9]+/g, ' ');

    for (const [source, target] of latinToCyrillicWordReplacements) {
        normalized = normalized.replaceAll(source, target);
    }

    return [...normalized]
        .map((character) => latinToCyrillicChars[character] ?? character)
        .join('')
        .replace(/\s+/g, ' ')
        .trim();
};

export const getHeroSearchAliases = (hero: HeroSearchHero): string[] => {
    const baseAliases = [
        hero.title,
        hero.name,
        ...(internetAliasesByHeroId[hero.id] ?? []),
        ...(manualRussianAliasesByHeroId[hero.id] ?? []),
    ];

    const aliases = new Set<string>();

    for (const alias of baseAliases) {
        const trimmedAlias = alias.trim();

        if (trimmedAlias === '') {
            continue;
        }

        aliases.add(trimmedAlias);

        const transliteratedAlias = transliterateAliasToCyrillic(trimmedAlias);

        if (transliteratedAlias !== '') {
            aliases.add(transliteratedAlias);
        }
    }

    return [...aliases];
};
