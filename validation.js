(function(){
  document.querySelectorAll('form[novalidate]').forEach(function(form){
    form.addEventListener('submit', function(e){
      var required = form.querySelectorAll('[required]');
      var firstInvalid = null;
      required.forEach(function(el){
        var isInvalid = false;
        if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) {
          isInvalid = false;
        } else if (!String(el.value || '').trim()) {
          isInvalid = true;
        }
        if (isInvalid) {
          el.classList.add('is-invalid');
          if (!firstInvalid) firstInvalid = el;
        } else {
          el.classList.remove('is-invalid');
          el.classList.add('is-valid');
        }
      });

      var asset = form.querySelector('[name="asset_number"]');
      if (asset && asset.value.trim() !== '' && !/^[0-9]{3}-[0-9]{6}$/.test(asset.value.trim())) {
        asset.classList.add('is-invalid');
        if (!firstInvalid) firstInvalid = asset;
      }

      if (firstInvalid) {
        e.preventDefault();
        e.stopPropagation();
        firstInvalid.focus();
      }
    });

    form.querySelectorAll('input, select, textarea').forEach(function(el){
      el.addEventListener('input', function(){
        if (String(el.value || '').trim()) {
          el.classList.remove('is-invalid');
        }
      });
    });
  });
})();
