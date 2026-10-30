// Runs before the app bundle so the first paint already has the right theme and direction.
try {
  var root = document.documentElement;
  var theme = localStorage.getItem('alphamaster.admin.theme');
  var dark = theme === 'dark' || (theme !== 'light' && matchMedia('(prefers-color-scheme: dark)').matches);
  if (dark) root.classList.add('dark');
  if (localStorage.getItem('alphamaster.admin.lang') === 'en') {
    root.lang = 'en';
    root.dir = 'ltr';
  }
} catch (e) {
  // Storage can be blocked; the app falls back to Arabic and the system theme.
}
