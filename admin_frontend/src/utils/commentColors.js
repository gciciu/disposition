export const COMMENT_COLORS = ['amber', 'red', 'green', 'blue', 'orange', 'gray']

export const COMMENT_COLOR_ROW = {
  amber:
    'border-amber-400 bg-amber-50 text-amber-950 dark:border-amber-500 dark:bg-amber-950/40 dark:text-amber-100',
  red: 'border-red-400 bg-red-50 text-red-950 dark:border-red-500 dark:bg-red-950/40 dark:text-red-100',
  green:
    'border-emerald-400 bg-emerald-50 text-emerald-950 dark:border-emerald-500 dark:bg-emerald-950/40 dark:text-emerald-100',
  blue: 'border-sky-400 bg-sky-50 text-sky-950 dark:border-sky-500 dark:bg-sky-950/40 dark:text-sky-100',
  orange:
    'border-orange-400 bg-orange-50 text-orange-950 dark:border-orange-500 dark:bg-orange-950/40 dark:text-orange-100',
  gray: 'border-gray-400 bg-gray-50 text-gray-900 dark:border-slate-500 dark:bg-slate-800/60 dark:text-slate-100',
}

export const commentRowClass = (color) =>
  COMMENT_COLOR_ROW[COMMENT_COLORS.includes(color) ? color : 'amber']
