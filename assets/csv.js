(() => {
  const refresh = document.getElementById('refreshBtn');
  if (refresh) refresh.addEventListener('click', () => window.location.reload());
  const pathInput = document.getElementById('csvPath');
  if (pathInput) pathInput.addEventListener('keydown', event => {
    if (event.key === 'Enter') { event.preventDefault(); pathInput.form?.requestSubmit(); }
  });
})();
