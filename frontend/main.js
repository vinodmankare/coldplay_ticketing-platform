import React, { useEffect, useState } from "https://esm.sh/react@18";
import { createRoot } from "https://esm.sh/react-dom@18/client";
import htm from "https://esm.sh/htm@3.1.1";

const html = htm.bind(React.createElement);
const API = "http://localhost:8080";

function App() {
  const [events, setEvents] = useState([]);
  const [status, setStatus] = useState({ type: "", text: "" });
  const [form, setForm] = useState({ event_id: "", user_name: "", user_email: "", ticket_count: 1 });

  useEffect(() => {
    fetch(`${API}/api/v1/events`)
      .then((r) => r.json())
      .then((data) => {
        setEvents(data.data || []);
        if ((data.data || []).length > 0) {
          setForm((prev) => ({ ...prev, event_id: String(data.data[0].id) }));
        }
      })
      .catch(() => setStatus({ type: "err", text: "Failed to load events." }));
  }, []);

  async function submit(e) {
    e.preventDefault();
    setStatus({ type: "", text: "" });

    const idempotencyKey = crypto.randomUUID();

    const response = await fetch(`${API}/api/v1/bookings`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "Idempotency-Key": idempotencyKey,
      },
      body: JSON.stringify({
        event_id: Number(form.event_id),
        user_name: form.user_name,
        user_email: form.user_email,
        ticket_count: Number(form.ticket_count),
      }),
    });

    const data = await response.json();
    if (!response.ok) {
      setStatus({ type: "err", text: data.message || "Booking failed." });
      return;
    }

    setStatus({ type: "ok", text: `Booking confirmed. Booking ID: ${data.booking_id}` });

    const eventsResponse = await fetch(`${API}/api/v1/events`);
    const eventsData = await eventsResponse.json();
    setEvents(eventsData.data || []);
  }

  return html`
    <div className="page">
      <div className="card">
        <h2>Coldplay Mumbai Ticket Booking</h2>
        <p className="muted">Prototype flow: inventory check, booking confirmation, and email outbox queue.</p>
      </div>

      <div className="card">
        <h3>Available Events</h3>
        <ul>
          ${events.map(
            (event) => html`<li key=${event.id}>${event.name} | ${event.venue} | Tickets left: ${event.available_tickets}</li>`
          )}
        </ul>
      </div>

      <form className="card" onSubmit=${submit}>
        <h3>Book Tickets</h3>
        <div className="grid">
          <label>
            Event
            <select value=${form.event_id} onChange=${(e) => setForm({ ...form, event_id: e.target.value })} required>
              ${events.map((event) => html`<option key=${event.id} value=${event.id}>${event.name}</option>`)}
            </select>
          </label>
          <label>
            Ticket Count (max 6)
            <input type="number" min="1" max="6" value=${form.ticket_count} onChange=${(e) => setForm({ ...form, ticket_count: e.target.value })} required />
          </label>
          <label>
            Your Name
            <input type="text" value=${form.user_name} onChange=${(e) => setForm({ ...form, user_name: e.target.value })} required />
          </label>
          <label>
            Your Email
            <input type="email" value=${form.user_email} onChange=${(e) => setForm({ ...form, user_email: e.target.value })} required />
          </label>
        </div>
        <p><button type="submit">Confirm Booking</button></p>
        ${status.text ? html`<p className=${status.type}>${status.text}</p>` : null}
      </form>
    </div>
  `;
}

createRoot(document.getElementById("root")).render(html`<${App} />`);
