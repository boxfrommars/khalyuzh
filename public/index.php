<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$isAdmin = $isAdmin ?? false;
$profile = appConfig()['profile'];
$apiUrl = $isAdmin ? '/admin/api.php' : '/api.php';
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Дневник калорий для рациона Халюжа">
  <title>Рацион Халюжа</title>

  <style>
    :root {
      color-scheme: light;
      --page: #f4f1ea;
      --surface: #fffdf8;
      --surface-soft: #faf8f2;
      --text: #25241f;
      --muted: #6f6b61;
      --line: #ded9ce;
      --focus: #4d6b5a;
      --neutral-bg: #eeebe3;
      --neutral-border: #d4cfc3;
      --good: #205f42;
      --good-bg: #e2f2e8;
      --good-border: #a8d0b7;
      --warning: #8b431b;
      --warning-bg: #fff0df;
      --warning-border: #e6b783;
      --danger: #8c332a;
    }

    * {
      box-sizing: border-box;
    }

    body {
      min-height: 100vh;
      margin: 0;
      padding: 40px 24px;
      background: var(--page);
      color: var(--text);
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      line-height: 1.5;
    }

    main {
      width: min(100%, 680px);
      margin: 0 auto;
      display: grid;
      gap: 24px;
    }

    .card {
      padding: clamp(24px, 6vw, 40px);
      border: 1px solid var(--line);
      border-radius: 24px;
      background: var(--surface);
      box-shadow: 0 18px 50px rgb(56 48 33 / 8%);
    }

    .page-header {
      margin-bottom: 30px;
    }

    .eyebrow {
      margin: 0 0 6px;
      color: var(--muted);
      font-size: 0.8rem;
      font-weight: 750;
      letter-spacing: 0.1em;
      text-transform: uppercase;
    }

    h1,
    h2 {
      margin: 0;
      line-height: 1.15;
      letter-spacing: -0.035em;
    }

    h1 {
      font-size: clamp(1.9rem, 7vw, 2.6rem);
    }

    h2 {
      font-size: 1.45rem;
    }

    .subtitle {
      margin: 10px 0 0;
      color: var(--muted);
    }

    form {
      display: grid;
      gap: 20px;
    }

    .field {
      display: grid;
      gap: 8px;
    }

    .field-row {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 16px;
    }

    label {
      font-weight: 650;
    }

    .input-wrap {
      display: flex;
      align-items: center;
      overflow: hidden;
      border: 1px solid var(--line);
      border-radius: 13px;
      background: #fff;
      transition: border-color 150ms ease, box-shadow 150ms ease;
    }

    .input-wrap:focus-within {
      border-color: var(--focus);
      box-shadow: 0 0 0 3px rgb(77 107 90 / 15%);
    }

    input {
      width: 100%;
      min-width: 0;
      padding: 12px 14px;
      border: 0;
      outline: 0;
      background: transparent;
      color: var(--text);
      font: inherit;
      font-size: 1rem;
      font-variant-numeric: tabular-nums;
    }

    input::placeholder {
      color: #aaa59a;
    }

    .unit {
      padding: 0 14px;
      border-left: 1px solid var(--line);
      color: var(--muted);
      font-size: 0.88rem;
      white-space: nowrap;
    }

    .hint {
      margin: 0;
      color: var(--muted);
      font-size: 0.82rem;
      overflow-wrap: anywhere;
    }

    .result {
      padding: 22px;
      border: 1px solid var(--neutral-border);
      border-radius: 17px;
      background: var(--neutral-bg);
      transition: color 180ms ease, background-color 180ms ease, border-color 180ms ease;
    }

    .result.in-range {
      border-color: var(--good-border);
      background: var(--good-bg);
      color: var(--good);
    }

    .result.out-of-range {
      border-color: var(--warning-border);
      background: var(--warning-bg);
      color: var(--warning);
    }

    .result-label {
      margin: 0;
      font-size: 0.82rem;
      font-weight: 750;
      letter-spacing: 0.07em;
      text-transform: uppercase;
    }

    .total {
      margin: 4px 0 8px;
      font-size: clamp(2rem, 9vw, 3rem);
      font-weight: 750;
      line-height: 1.1;
      letter-spacing: -0.04em;
      font-variant-numeric: tabular-nums;
    }

    .comparison {
      margin-left: 10px;
      font-size: 0.42em;
      font-weight: 700;
      letter-spacing: -0.01em;
      white-space: nowrap;
      opacity: 0.82;
    }

    .status,
    .breakdown {
      margin: 0;
    }

    .status {
      font-weight: 650;
    }

    .breakdown {
      margin-top: 12px;
      padding-top: 12px;
      border-top: 1px solid currentColor;
      color: var(--muted);
      font-size: 0.86rem;
      opacity: 0.84;
    }

    .result.in-range .breakdown,
    .result.out-of-range .breakdown {
      color: inherit;
    }

    .range-note {
      margin: -8px 0 0;
      color: var(--muted);
      font-size: 0.82rem;
      text-align: center;
    }

    .actions {
      display: flex;
      align-items: center;
      gap: 14px;
    }

    button {
      appearance: none;
      padding: 12px 18px;
      border: 0;
      border-radius: 12px;
      cursor: pointer;
      font: inherit;
      font-weight: 700;
    }

    button:disabled {
      cursor: wait;
      opacity: 0.6;
    }

    .primary-button {
      background: var(--text);
      color: #fff;
    }

    .primary-button:hover {
      background: #3c3a33;
    }

    button:focus-visible,
    .history-summary:focus-visible {
      outline: 3px solid rgb(77 107 90 / 30%);
      outline-offset: 2px;
    }

    .feedback {
      margin: 0;
      color: var(--good);
      font-size: 0.88rem;
      font-weight: 650;
    }

    .feedback.error {
      color: var(--danger);
    }

    .history-header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 20px;
      margin-bottom: 22px;
    }

    .history-average {
      display: grid;
      justify-items: end;
      gap: 5px;
      margin-right: 18px;
    }

    .history-average[hidden] {
      display: none;
    }

    .history-average-label {
      margin: 0;
      color: var(--muted);
      font-size: 0.78rem;
    }

    .history-average-result {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 8px;
    }

    .history-average-value {
      font-size: 0.9rem;
      font-variant-numeric: tabular-nums;
      white-space: nowrap;
    }

    .history-list {
      display: grid;
      gap: 12px;
    }

    .empty-history {
      margin: 0;
      padding: 26px;
      border: 1px dashed var(--line);
      border-radius: 15px;
      color: var(--muted);
      text-align: center;
    }

    .empty-history p {
      margin: 0;
    }

    .empty-history .text-button {
      margin-top: 8px;
    }

    .history-item {
      overflow: hidden;
      border: 1px solid var(--line);
      border-radius: 15px;
      background: var(--surface-soft);
    }

    .history-summary {
      display: grid;
      grid-template-columns: 14px auto auto minmax(0, 1fr);
      align-items: center;
      gap: 10px;
      padding: 17px;
      cursor: pointer;
      list-style: none;
    }

    .history-summary::-webkit-details-marker {
      display: none;
    }

    .history-summary::before {
      content: "›";
      color: var(--muted);
      font-size: 1.35rem;
      line-height: 1;
      transform-origin: center;
      transition: transform 150ms ease;
    }

    .history-item[open] .history-summary::before {
      transform: rotate(90deg);
    }

    .history-summary-total {
      justify-self: start;
      font-size: 0.9rem;
      font-weight: 750;
      font-variant-numeric: tabular-nums;
      white-space: nowrap;
    }

    .history-item[open] .history-summary-total {
      display: none;
    }

    .history-expanded {
      padding: 16px 17px 17px;
      border-top: 1px solid var(--line);
    }

    .history-date {
      margin: 0;
      font-weight: 400;
    }

    .history-total {
      margin: 0;
      font-size: 1.25rem;
      font-weight: 750;
      font-variant-numeric: tabular-nums;
    }

    .history-comparison {
      margin-left: 6px;
      font-size: 0.65em;
      white-space: nowrap;
      opacity: 0.78;
    }

    .badge {
      justify-self: end;
      padding: 5px 9px;
      border-radius: 999px;
      font-size: 0.76rem;
      font-weight: 750;
      white-space: nowrap;
    }

    .badge.good {
      background: var(--good-bg);
      color: var(--good);
    }

    .badge.warning {
      background: var(--warning-bg);
      color: var(--warning);
    }

    .history-details {
      margin: 12px 0;
      color: var(--muted);
      font-size: 0.84rem;
    }

    .history-actions {
      display: flex;
      align-items: center;
      gap: 14px;
      justify-content: flex-start;
      padding-top: 12px;
      border-top: 1px solid var(--line);
    }

    .text-button {
      padding: 4px 0;
      background: transparent;
      color: var(--focus);
      font-size: 0.85rem;
    }

    .text-button.danger {
      color: var(--danger);
    }

    @media (max-width: 560px) {
      body {
        padding: 12px;
      }

      main {
        gap: 14px;
      }

      .card {
        border-radius: 19px;
      }

      .field-row {
        grid-template-columns: 1fr;
      }

      .history-summary {
        grid-template-columns: 14px auto minmax(0, 1fr);
        gap: 8px;
      }

      .history-summary .badge {
        grid-column: 2 / -1;
        justify-self: start;
        text-align: left;
        white-space: normal;
        line-height: 1.25;
      }

      .actions,
      .history-header {
        align-items: stretch;
        flex-direction: column;
      }

      .history-average {
        justify-items: start;
        margin-right: 0;
      }

      .history-average-result {
        justify-content: flex-start;
        flex-wrap: wrap;
      }

      .primary-button {
        width: 100%;
      }
    }

    @media (prefers-reduced-motion: reduce) {
      *,
      *::before,
      *::after {
        scroll-behavior: auto !important;
        transition-duration: 0.01ms !important;
      }
    }
  </style>
</head>

<body>
  <main>
    <section class="card" aria-labelledby="page-title">
      <header class="page-header">
        <p class="eyebrow">Дневник рациона</p>
        <h1 id="page-title">Халюж, как успехи?</h1>
        <p class="subtitle"><?= $isAdmin
            ? 'Запишите рацион за день — данные сохранятся на сервере.'
            : 'Посчитайте рацион и посмотрите историю Халюжа.' ?></p>
      </header>

      <form id="food-form" autocomplete="off" novalidate>
        <div class="field">
          <label for="entry-date">Дата рациона</label>
          <div class="input-wrap">
            <input id="entry-date" name="entryDate" type="date" required>
          </div>
          <p class="hint">По умолчанию выбрана вчерашняя дата</p>
        </div>

        <div class="field-row">
          <div class="field">
            <label for="dry-food">Сухой корм</label>
            <div class="input-wrap">
              <input id="dry-food" name="dryFood" type="number" inputmode="decimal" min="0" step="any" placeholder="0">
              <span class="unit" aria-hidden="true">г</span>
            </div>
            <p class="hint" id="dry-hint"></p>
          </div>

          <div class="field">
            <label for="wet-food">Влажный корм</label>
            <div class="input-wrap">
              <input id="wet-food" name="wetFood" type="number" inputmode="decimal" min="0" step="any" placeholder="0">
              <span class="unit" aria-hidden="true">банки</span>
            </div>
            <p class="hint" id="wet-hint"></p>
          </div>
        </div>

        <section class="result" id="result" aria-live="polite" aria-atomic="true">
          <p class="result-label">Итого</p>
          <p class="total" id="total">
            <span id="total-value">—</span> <span class="comparison" id="comparison"></span>
          </p>
          <p class="status" id="status">Введите количество корма</p>
          <p class="breakdown" id="breakdown" hidden></p>
        </section>

        <p class="range-note" id="range-note"></p>

        <?php if ($isAdmin): ?>
          <div class="actions">
            <button class="primary-button" id="save-button" type="submit">Сохранить день</button>
            <p class="feedback" id="feedback" role="status"></p>
          </div>
        <?php endif; ?>
      </form>
    </section>

    <section class="card" aria-labelledby="history-title">
      <header class="history-header">
        <h2 id="history-title">История</h2>
        <div class="history-average" id="history-average" aria-live="polite" hidden>
          <p class="history-average-label">Среднее за 7 дней</p>
          <div class="history-average-result">
            <strong class="history-average-value" id="history-average-value"></strong>
            <span class="badge" id="history-average-badge"></span>
          </div>
        </div>
      </header>
      <div class="history-list" id="history-list"></div>
    </section>
  </main>

  <script>
    "use strict";

    const IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;
    const API_URL = <?= json_encode($apiUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const DEFAULT_PROFILE = Object.freeze(<?= json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);

    const form = document.querySelector("#food-form");
    const dateInput = document.querySelector("#entry-date");
    const dryInput = document.querySelector("#dry-food");
    const wetInput = document.querySelector("#wet-food");
    const dryHint = document.querySelector("#dry-hint");
    const wetHint = document.querySelector("#wet-hint");
    const result = document.querySelector("#result");
    const totalValueOutput = document.querySelector("#total-value");
    const comparisonOutput = document.querySelector("#comparison");
    const statusOutput = document.querySelector("#status");
    const breakdownOutput = document.querySelector("#breakdown");
    const rangeNote = document.querySelector("#range-note");
    const saveButton = document.querySelector("#save-button");
    const feedback = document.querySelector("#feedback");
    const historyList = document.querySelector("#history-list");
    const historyAverage = document.querySelector("#history-average");
    const historyAverageValue = document.querySelector("#history-average-value");
    const historyAverageBadge = document.querySelector("#history-average-badge");

    const state = {
      records: [],
      activeProfile: { ...DEFAULT_PROFILE }
    };

    const calorieFormatter = new Intl.NumberFormat("ru-RU", {
      minimumFractionDigits: 1,
      maximumFractionDigits: 1
    });
    const compactNumberFormatter = new Intl.NumberFormat("ru-RU", {
      maximumFractionDigits: 3
    });
    const dateFormatter = new Intl.DateTimeFormat("ru-RU", {
      day: "numeric",
      month: "long",
      year: "numeric"
    });

    function toLocalISODate(date) {
      const year = date.getFullYear();
      const month = String(date.getMonth() + 1).padStart(2, "0");
      const day = String(date.getDate()).padStart(2, "0");
      return `${year}-${month}-${day}`;
    }

    function yesterdayISO() {
      const date = new Date();
      date.setDate(date.getDate() - 1);
      return toLocalISODate(date);
    }

    function formatDate(dateString) {
      return dateFormatter.format(new Date(`${dateString}T12:00:00`));
    }

    function formatCalories(value) {
      return calorieFormatter.format(value);
    }

    function formatCompact(value) {
      return compactNumberFormatter.format(value);
    }

    function canUnit(value) {
      if (!Number.isInteger(value)) return "банки";
      const lastTwoDigits = Math.abs(value) % 100;
      const lastDigit = lastTwoDigits % 10;
      if (lastTwoDigits >= 11 && lastTwoDigits <= 14) return "банок";
      if (lastDigit === 1) return "банка";
      if (lastDigit >= 2 && lastDigit <= 4) return "банки";
      return "банок";
    }

    function numberFrom(input) {
      return Number.isFinite(input.valueAsNumber) ? input.valueAsNumber : 0;
    }

    async function requestJSON(url, options = {}) {
      const response = await fetch(url, {
        cache: "no-store",
        ...options,
        headers: {
          Accept: "application/json",
          ...options.headers
        }
      });

      if (response.status === 204) return null;

      let payload;
      try {
        payload = await response.json();
      } catch (error) {
        throw new Error("Сервер вернул некорректный ответ");
      }

      if (!response.ok) {
        throw new Error(payload.error || "Запрос завершился ошибкой");
      }

      return payload;
    }

    function renderHistoryLoading() {
      historyAverage.hidden = true;
      historyList.innerHTML = '<p class="empty-history">Загружаем историю…</p>';
    }

    function renderHistoryError() {
      historyAverage.hidden = true;
      historyList.innerHTML = `
        <div class="empty-history">
          <p>Не удалось загрузить историю.</p>
          <button class="text-button" type="button" data-action="retry">Повторить</button>
        </div>`;
    }

    async function refreshRecords({ showLoading = false } = {}) {
      if (showLoading) renderHistoryLoading();

      try {
        const payload = await requestJSON(API_URL);
        if (!Array.isArray(payload.records)) {
          throw new Error("Сервер вернул некорректную историю");
        }

        state.records = payload.records;
        renderHistory();
        return true;
      } catch (error) {
        renderHistoryError();
        return false;
      }
    }

    function profileFromRecord(record) {
      return {
        catWeight: record.catWeight,
        dryName: record.dryName,
        dryCaloriesPerGram: record.dryCaloriesPerGram,
        wetName: record.wetName,
        wetCaloriesPerCan: record.wetCaloriesPerCan,
        targetMin: record.targetMin,
        targetMax: record.targetMax
      };
    }

    function calculateValues(dryAmount, wetAmount, profile) {
      const dryCalories = dryAmount * profile.dryCaloriesPerGram;
      const wetCalories = wetAmount * profile.wetCaloriesPerCan;
      const totalCalories = dryCalories + wetCalories;
      return {
        dryCalories,
        wetCalories,
        totalCalories,
        isInRange: totalCalories >= profile.targetMin && totalCalories <= profile.targetMax
      };
    }

    function updateLabels(profile) {
      dryHint.textContent = `${profile.dryName || "Сухой корм"} · ${formatCompact(profile.dryCaloriesPerGram)} ккал/г`;
      wetHint.textContent = `${profile.wetName || "Влажный корм"} · ${formatCompact(profile.wetCaloriesPerCan)} ккал/банка`;
      rangeNote.textContent = `Целевой диапазон: ${formatCompact(profile.targetMin)}–${formatCompact(profile.targetMax)} ккал`;
    }

    function showNeutralResult() {
      result.className = "result";
      totalValueOutput.textContent = "—";
      comparisonOutput.textContent = "";
      statusOutput.textContent = "Введите количество корма";
      breakdownOutput.textContent = "";
      breakdownOutput.hidden = true;
    }

    function updateResult() {
      const profile = state.activeProfile;
      updateLabels(profile);

      if (dryInput.value === "" && wetInput.value === "") {
        showNeutralResult();
        return null;
      }

      const values = calculateValues(numberFrom(dryInput), numberFrom(wetInput), profile);
      result.className = `result ${values.isInRange ? "in-range" : "out-of-range"}`;
      totalValueOutput.textContent = `${formatCalories(values.totalCalories)} ккал`;

      if (values.isInRange) {
        comparisonOutput.textContent = "";
        statusOutput.textContent = "В пределах нормы";
      } else if (values.totalCalories < profile.targetMin) {
        comparisonOutput.textContent = `< ${formatCompact(profile.targetMin)} ккал`;
        statusOutput.textContent = `Не хватает ${formatCalories(profile.targetMin - values.totalCalories)} ккал до нормы`;
      } else {
        comparisonOutput.textContent = `> ${formatCompact(profile.targetMax)} ккал`;
        statusOutput.textContent = `Превышение на ${formatCalories(values.totalCalories - profile.targetMax)} ккал`;
      }

      breakdownOutput.textContent = `Сухой: ${formatCalories(values.dryCalories)} ккал · влажный: ${formatCalories(values.wetCalories)} ккал`;
      breakdownOutput.hidden = false;
      return values;
    }

    function setFeedback(message, isError = false) {
      if (!feedback) return;
      feedback.textContent = message;
      feedback.classList.toggle("error", isError);
    }

    function loadDate(date) {
      const record = state.records.find((item) => item.date === date);
      dateInput.value = date;

      if (record) {
        state.activeProfile = profileFromRecord(record);
        dryInput.value = record.dryAmount;
        wetInput.value = record.wetAmount;
        if (saveButton) saveButton.textContent = "Обновить запись";
      } else {
        state.activeProfile = { ...DEFAULT_PROFILE };
        dryInput.value = "";
        wetInput.value = "";
        if (saveButton) saveButton.textContent = "Сохранить день";
      }

      setFeedback("");
      updateResult();
    }

    function validateEntry() {
      if (!dateInput.value) return "Выберите дату";
      if (dryInput.value === "" && wetInput.value === "") return "Введите количество хотя бы одного корма";
      return "";
    }

    function createRecord() {
      return {
        date: dateInput.value,
        dryAmount: numberFrom(dryInput),
        wetAmount: numberFrom(wetInput)
      };
    }

    function escapeHTML(value) {
      return String(value)
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#039;");
    }

    function describeRange(totalCalories, targetMin, targetMax) {
      const isBelowRange = totalCalories < targetMin;
      const isInRange = !isBelowRange && totalCalories <= targetMax;

      if (isInRange) {
        return {
          isBelowRange,
          isInRange,
          badgeText: "В норме"
        };
      }

      const boundary = isBelowRange ? targetMin : targetMax;
      const deviationCalories = isBelowRange
        ? targetMin - totalCalories
        : totalCalories - targetMax;
      const deviationPercent = boundary > 0
        ? ` (${formatCalories(deviationCalories / boundary * 100)}%)`
        : "";

      return {
        isBelowRange,
        isInRange,
        badgeText: `${isBelowRange ? "Ниже" : "Выше"} нормы на ${formatCalories(deviationCalories)} ккал${deviationPercent}`
      };
    }

    function renderHistoryAverage(records) {
      if (!records.length) {
        historyAverage.hidden = true;
        return;
      }

      const latestDate = records[0].date;
      const firstDate = new Date(`${latestDate}T12:00:00`);
      firstDate.setDate(firstDate.getDate() - 6);
      const firstDateISO = toLocalISODate(firstDate);
      const recentRecords = records.filter((record) =>
        record.date >= firstDateISO && record.date <= latestDate
      );
      const averages = recentRecords.reduce((result, record) => {
        const values = calculateValues(record.dryAmount, record.wetAmount, profileFromRecord(record));
        result.totalCalories += values.totalCalories;
        result.targetMin += record.targetMin;
        result.targetMax += record.targetMax;
        return result;
      }, { totalCalories: 0, targetMin: 0, targetMax: 0 });
      const recordCount = recentRecords.length;
      const averageCalories = averages.totalCalories / recordCount;
      const averageMin = averages.targetMin / recordCount;
      const averageMax = averages.targetMax / recordCount;
      const range = describeRange(averageCalories, averageMin, averageMax);

      historyAverageValue.textContent = `${formatCalories(averageCalories)} ккал`;
      historyAverageBadge.textContent = range.badgeText;
      historyAverageBadge.className = `badge ${range.isInRange ? "good" : "warning"}`;
      historyAverage.hidden = false;
    }

    function renderHistory() {
      const records = [...state.records].sort((a, b) => b.date.localeCompare(a.date));
      renderHistoryAverage(records);

      if (!records.length) {
        historyList.innerHTML = `<p class="empty-history">${IS_ADMIN
          ? "Сохраните первый день — он появится здесь."
          : "История пока пуста."}</p>`;
        return;
      }

      historyList.innerHTML = records.map((record) => {
        const values = calculateValues(record.dryAmount, record.wetAmount, profileFromRecord(record));
        const range = describeRange(values.totalCalories, record.targetMin, record.targetMax);
        const comparison = range.isInRange
          ? ""
          : range.isBelowRange
            ? `&lt; ${formatCompact(record.targetMin)} ккал`
            : `&gt; ${formatCompact(record.targetMax)} ккал`;

        return `
          <details class="history-item">
            <summary class="history-summary">
              <span class="history-date"><time datetime="${record.date}">${formatDate(record.date)}</time></span>
              <span class="history-summary-total">${formatCalories(values.totalCalories)} ккал</span>
              <span class="badge ${range.isInRange ? "good" : "warning"}">${range.badgeText}</span>
            </summary>
            <div class="history-expanded">
              <p class="history-total">
                ${formatCalories(values.totalCalories)} ккал${comparison ? ` <span class="history-comparison">${comparison}</span>` : ""}
              </p>
              <p class="history-details">
                ${escapeHTML(record.dryName)}: ${formatCompact(record.dryAmount)} г ·
                ${escapeHTML(record.wetName)}: ${formatCompact(record.wetAmount)} ${canUnit(record.wetAmount)}<br>
                Вес: ${formatCompact(record.catWeight)} кг · Норма: ${formatCompact(record.targetMin)}–${formatCompact(record.targetMax)} ккал
              </p>
              ${IS_ADMIN ? `
                <div class="history-actions">
                  <button class="text-button" type="button" data-action="edit" data-date="${record.date}">Изменить</button>
                  <button class="text-button danger" type="button" data-action="delete" data-date="${record.date}">Удалить</button>
                </div>` : ""}
            </div>
          </details>`;
      }).join("");
    }

    form.addEventListener("input", (event) => {
      const input = event.target;
      if (input.type === "number" && input.value !== "" && input.valueAsNumber < 0) {
        input.value = "0";
      }
      setFeedback("");
      updateResult();
    });

    dateInput.addEventListener("change", () => loadDate(dateInput.value));

    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      if (!IS_ADMIN) return;

      const validationMessage = validateEntry();

      if (validationMessage) {
        setFeedback(validationMessage, true);
        return;
      }

      const record = createRecord();
      const isUpdate = state.records.some((item) => item.date === record.date);
      saveButton.disabled = true;
      setFeedback("Сохраняем…");

      try {
        await requestJSON(`${API_URL}?date=${encodeURIComponent(record.date)}`, {
          method: "PUT",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            dryAmount: record.dryAmount,
            wetAmount: record.wetAmount
          })
        });

        const refreshed = await refreshRecords();
        if (refreshed) loadDate(record.date);
        setFeedback(refreshed
          ? isUpdate ? "Запись обновлена" : "День сохранён"
          : "День сохранён, но историю не удалось обновить", !refreshed);
      } catch (error) {
        setFeedback("Не удалось сохранить запись. Проверьте соединение.", true);
      } finally {
        saveButton.disabled = false;
      }
    });

    historyList.addEventListener("click", async (event) => {
      const button = event.target.closest("button[data-action]");
      if (!button) return;

      const { action, date } = button.dataset;
      if (action === "retry") {
        await refreshRecords({ showLoading: true });
        return;
      }

      if (action === "edit") {
        loadDate(date);
        document.querySelector("#page-title").scrollIntoView({ behavior: "smooth" });
        return;
      }

      if (action === "delete" && window.confirm(`Удалить запись за ${formatDate(date)}?`)) {
        button.disabled = true;
        try {
          await requestJSON(`${API_URL}?date=${encodeURIComponent(date)}`, { method: "DELETE" });
          const refreshed = await refreshRecords();
          if (refreshed && dateInput.value === date) loadDate(date);
          setFeedback(refreshed ? "Запись удалена" : "Запись удалена, но историю не удалось обновить", !refreshed);
        } catch (error) {
          button.disabled = false;
          setFeedback("Не удалось удалить запись. Проверьте соединение.", true);
        }
      }
    });

    async function initialize() {
      dateInput.max = toLocalISODate(new Date());
      const initialDate = yesterdayISO();
      loadDate(initialDate);

      if (await refreshRecords({ showLoading: true })) {
        loadDate(initialDate);
      }
    }

    initialize();
  </script>
</body>
</html>
