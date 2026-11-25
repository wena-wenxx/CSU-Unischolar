import api from "../services/api";

export async function downloadFile(url, body, fallbackName) {
  let response;
  try {
    response = await api.post(url, body, { responseType: "blob" });
  } catch (err) {
    const data = err?.response?.data;
    if (data instanceof Blob) {
      try {
        const parsed = JSON.parse(await data.text());
        if (parsed?.message) err.response.data = parsed;
      } catch {
      }
    }
    throw err;
  }

  const disposition = response.headers?.["content-disposition"] || "";
  const name = disposition.match(/filename="?([^";]+)"?/)?.[1] || fallbackName;
  const link = document.createElement("a");
  link.href = URL.createObjectURL(response.data);
  link.download = name;
  document.body.appendChild(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(link.href), 10000);
  return name;
}
