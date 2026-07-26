"use strict";

export function pageConfig() {
  const node = document.querySelector("#page-config");
  if (!node) throw new Error("Page configuration is missing");
  return JSON.parse(node.textContent);
}

export function toLocalISODate(date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
}

export const dateFormatter = new Intl.DateTimeFormat("ru-RU", {
  day: "numeric",
  month: "long",
  year: "numeric"
});

export function formatDate(dateString) {
  return dateFormatter.format(new Date(`${dateString}T12:00:00`));
}

export function escapeHTML(value) {
  return String(value)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

export async function requestJSON(url, options = {}) {
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
