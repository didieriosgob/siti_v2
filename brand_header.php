<?php
$brand_back_href = $brand_back_href ?? 'index.php';
$brand_back_text = $brand_back_text ?? 'Inicio';
$brand_eyebrow   = $brand_eyebrow   ?? 'SECOED · Plataforma institucional';
$brand_title     = $brand_title     ?? 'Módulo SITI';
$brand_subtitle  = $brand_subtitle  ?? 'Sistema Integral de Tickets Informático';
$brand_badge     = $brand_badge     ?? '';
?>
<section class="brand-hero mb-4">
  <div class="brand-hero__top">
    <a href="<?= htmlspecialchars($brand_back_href, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-ghost">← <?= htmlspecialchars($brand_back_text, ENT_QUOTES, 'UTF-8') ?></a>
    <?php if ($brand_badge !== ''): ?>
      <span class="brand-hero__badge"><?= htmlspecialchars($brand_badge, ENT_QUOTES, 'UTF-8') ?></span>
    <?php endif; ?>
  </div>

  <div class="brand-hero__main">
    <div class="brand-hero__logos" aria-hidden="true">
      <span class="brand-hero__siti">
        <img src="siti_logo_blanco.png" alt="">
      </span>
    </div>

    <div class="brand-hero__copy">
      <div class="eyebrow brand-hero__eyebrow"><?= htmlspecialchars($brand_eyebrow, ENT_QUOTES, 'UTF-8') ?></div>
      <h1 class="brand-hero__title"><?= htmlspecialchars($brand_title, ENT_QUOTES, 'UTF-8') ?></h1>
      <p class="brand-hero__subtitle mb-0"><?= htmlspecialchars($brand_subtitle, ENT_QUOTES, 'UTF-8') ?></p>
    </div>
  </div>
</section>
