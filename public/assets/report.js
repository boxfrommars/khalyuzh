"use strict";

import {
  formatDate,
  pageConfig,
  renderHistoryChart,
  withCalendarRollingAverage
} from "./common.js";

const config = pageConfig();
const printButton = document.querySelector("#print-report");
const weightChart = document.querySelector("#report-weight-chart");
const calorieChart = document.querySelector("#report-calorie-chart");

const weightFormatter = new Intl.NumberFormat("ru-RU", {
  minimumFractionDigits: 2,
  maximumFractionDigits: 2
});
const calorieFormatter = new Intl.NumberFormat("ru-RU", {
  minimumFractionDigits: 1,
  maximumFractionDigits: 1
});
const compactFormatter = new Intl.NumberFormat("ru-RU", {
  maximumFractionDigits: 2
});

function formatWeight(value) {
  return `${weightFormatter.format(value)} кг`;
}

function formatCalories(value) {
  return `${calorieFormatter.format(value)} ккал`;
}

function formatCount(value, one, few, many) {
  const lastTwo = Math.abs(value) % 100;
  const last = lastTwo % 10;
  const word = lastTwo >= 11 && lastTwo <= 14
    ? many
    : last === 1 ? one : last >= 2 && last <= 4 ? few : many;
  return `${value} ${word}`;
}

function formatMeasurementCount(value) {
  return formatCount(value, "измерение", "измерения", "измерений");
}

function formatRecordedDayCount(value) {
  return `${formatCount(value, "день", "дня", "дней")} с записями`;
}

function withContextualRollingAverage(visiblePoints, contextPoints) {
  const rollingByDate = new Map(
    withCalendarRollingAverage(Array.isArray(contextPoints) ? contextPoints : visiblePoints)
      .map((point) => [point.date, point])
  );

  return visiblePoints.map((point) => {
    const contextualPoint = rollingByDate.get(point.date);
    return {
      ...point,
      rollingAverage: contextualPoint?.rollingAverage ?? point.value,
      rollingCount: contextualPoint?.rollingCount ?? 1
    };
  });
}

if (weightChart) {
  const weightPoints = withContextualRollingAverage(config.weightPoints, config.weightContextPoints);
  const weightAverageDescription = (point) =>
    `Среднее за 7 дней: ${formatWeight(point.rollingAverage)} (${formatMeasurementCount(point.rollingCount)})`;

  renderHistoryChart(weightChart, {
    points: weightPoints,
    maxPoints: null,
    pointLimit: 60,
    emptyMessage: "Нет взвешиваний за выбранный период.",
    ariaLabel: "Динамика веса: фактический вес и семидневное среднее по датам",
    ariaDescription: "Точки и бледная линия показывают фактические взвешивания выбранного периода. Основная линия показывает среднее по имеющимся измерениям за предыдущие 7 календарных дней и учитывает до 6 дней перед периодом. Обе линии соединяют соседние точки; пропуски не считаются нулём.",
    averageLabel: "Среднее за 7 дней",
    seriesLabel: "Фактический вес",
    axisLabel: "кг",
    nonNegative: false,
    formatAxisValue: (value) => compactFormatter.format(value),
    formatValue: formatWeight,
    pointLabel: (point) =>
      `${formatDate(point.date)}: фактический вес ${formatWeight(point.value)}. ${weightAverageDescription(point)}.`,
    tooltipLines: (point) => [
      formatDate(point.date),
      `Фактически: ${formatWeight(point.value)}`,
      weightAverageDescription(point)
    ]
  });
}

if (calorieChart) {
  const caloriePoints = withContextualRollingAverage(config.foodPoints, config.foodContextPoints);
  const calorieAverageDescription = (point) =>
    `Среднее за 7 дней: ${formatCalories(point.rollingAverage)} (${formatRecordedDayCount(point.rollingCount)})`;
  renderHistoryChart(calorieChart, {
    points: caloriePoints,
    maxPoints: null,
    pointLimit: 60,
    emptyMessage: "Нет записей о кормлении за выбранный период.",
    ariaLabel: "Динамика питания: дневная калорийность и среднее за 7 дней",
    ariaDescription: "Точки и бледная линия показывают дневную калорийность выбранного периода. Основная линия показывает среднее по имеющимся записям за предыдущие 7 календарных дней и учитывает до 6 дней перед периодом. Обе линии соединяют соседние точки; пропуски не считаются нулём.",
    averageLabel: "Среднее за 7 дней",
    seriesLabel: "Дневная калорийность",
    axisLabel: "ккал",
    formatAxisValue: (value) => compactFormatter.format(value),
    formatValue: formatCalories,
    pointLabel: (point) =>
      `${formatDate(point.date)}: фактическая калорийность ${formatCalories(point.value)}. ${calorieAverageDescription(point)}.`,
    tooltipLines: (point) => [
      formatDate(point.date),
      `Фактически: ${formatCalories(point.value)}`,
      calorieAverageDescription(point)
    ]
  });
}

if (printButton) {
  printButton.addEventListener("click", () => window.print());
}
