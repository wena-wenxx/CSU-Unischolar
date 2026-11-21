/*
  Search helpers for lists filtered in the browser.
  - Not case-sensitive, and accents do not matter: "anuber" finds "AÑUBER".
  - A Student ID matches with or without the dash: "202600001" finds "2026-00001".
  - Every word typed must be found.
*/
const plain = (text) =>
  String(text ?? "")
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
    .toLowerCase();

export function searchWords(query) {
  return plain(query).split(/\s+/).filter(Boolean);
}

export function matchesWords(text, words) {
  if (!words.length) return true;
  const haystack = plain(text);
  const digits = haystack.replace(/\D/g, "");
  return words.every((word) => {
    if (haystack.includes(word)) return true;
    const wordDigits = word.replace(/\D/g, "");
    return wordDigits.length >= 4 && wordDigits.length === word.replace(/-/g, "").length && digits.includes(wordDigits);
  });
}
