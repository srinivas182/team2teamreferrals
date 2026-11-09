/** Fictional portal sample data. The signed-in user is Jordan Hale, Red Rock Realty Group, St. George, UT. */
import type { Temp } from "../ui/kit";

export type Incoming = { id: number; from: string; fromTeam: string; fromCity: string; city: string; kind: "Buyer" | "Seller" | "Buyer and seller";
  temp: Temp; price: string; mid: number; fee: number; financing: string; timeline: string; minutesLeft: number; total: number; note: string; trust: number };

export const incoming: Incoming[] = [
  { id: 4822, from: "Hannah Price", fromTeam: "Capitol Key Realty", fromCity: "Austin, TX", city: "St. George, UT", kind: "Buyer", temp: "hot", price: "$520k–$600k", mid: 560000, fee: 30, financing: "Pre-approved", timeline: "Within 30 days", minutesLeft: 134, total: 1440, note: "Relocating for a hospital job in January. Wants single-level, 3+ bed, near Little Valley.", trust: 91 },
  { id: 4819, from: "Keisha Grant", fromTeam: "Bay Area Living", fromCity: "Tampa, FL", city: "St. George, UT", kind: "Seller", temp: "warm", price: "$700k–$780k", mid: 740000, fee: 30, financing: "Owns outright", timeline: "1–3 months", minutesLeft: 610, total: 1440, note: "Second home in Entrada. Moving to Florida full time.", trust: 90 },
  { id: 4811, from: "Luis Ortega", fromTeam: "Alamo Front Door", fromCity: "San Antonio, TX", city: "Hurricane, UT", kind: "Buyer", temp: "future", price: "$380k–$450k", mid: 415000, fee: 25, financing: "Needs lender", timeline: "Spring 2027", minutesLeft: 1210, total: 1440, note: "Retiring next year, wants to be near Zion.", trust: 88 },
];

export type Stage = "New" | "Contacted" | "Showing" | "Offer" | "Under contract" | "Nurture";
export type Lead = { id: number; name: string; from: string; temp: Temp; price: string; stage: Stage; next: string; due: string; overdue?: boolean; value: number };
export const pipeline: Lead[] = [
  { id: 4807, name: "Marcus & Elena Reyes", from: "Bayou City Homes", temp: "hot", price: "$480k–$540k", stage: "Showing", next: "Second showing, Saturday", due: "Sat 10:00", value: 3825 },
  { id: 4801, name: "Dana Kimura", from: "Desert Peak Group", temp: "hot", price: "$610k–$650k", stage: "Offer", next: "Counter-offer reply", due: "Today 5:00 PM", value: 4725 },
  { id: 4796, name: "Paul Wexler", from: "Peachtree Partners", temp: "warm", price: "$350k–$400k", stage: "Contacted", next: "Weekly update due", due: "Overdue 1 day", overdue: true, value: 2810 },
  { id: 4790, name: "Grace Liu", from: "Capitol Key Realty", temp: "warm", price: "$900k–$1.1M", stage: "Under contract", next: "Appraisal", due: "Nov 14", value: 7500 },
  { id: 4788, name: "The Okafor family", from: "Bay Area Living", temp: "future", price: "$450k–$500k", stage: "Nurture", next: "Monthly check-in", due: "Dec 1", value: 3560 },
  { id: 4812, name: "Rachel Stone", from: "Alamo Front Door", temp: "hot", price: "$300k–$340k", stage: "New", next: "First call within 2 h", due: "1h 20m left", value: 2400 },
  { id: 4784, name: "Ivan Petrov", from: "Old Pueblo Partners", temp: "future", price: "$550k–$600k", stage: "Nurture", next: "Monthly check-in", due: "Dec 3", value: 4320 },
];
export const stages: Stage[] = ["New", "Contacted", "Showing", "Offer", "Under contract", "Nurture"];

export type Sent = { id: number; lead: string; to: string; toTeam: string; city: string; temp: Temp; status: string; tone: "info" | "success" | "warning" | "neutral" | "orange"; updated: string; fee: string };
export const sent: Sent[] = [
  { id: 4821, lead: "Tom & Ana Brennan", to: "Chris Delgado", toTeam: "Bayou City Homes", city: "Houston, TX", temp: "hot", status: "Contacted", tone: "info", updated: "12 min ago", fee: "$3,638 est." },
  { id: 4815, lead: "Sophie Grant", to: "Monique Hayes", toTeam: "Peachtree Partners", city: "Atlanta, GA", temp: "warm", status: "Showing", tone: "info", updated: "Yesterday", fee: "$2,925 est." },
  { id: 4802, lead: "Ben Carter", to: "Maria Alvarez", toTeam: "Desert Peak Group", city: "Phoenix, AZ", temp: "hot", status: "Under contract", tone: "orange", updated: "2 days ago", fee: "$4,050 est." },
  { id: 4774, lead: "Lucy Nguyen", to: "Hannah Price", toTeam: "Capitol Key Realty", city: "Austin, TX", temp: "warm", status: "Closed · fee due", tone: "warning", updated: "Nov 2", fee: "$3,210 due" },
  { id: 4741, lead: "Omar Haddad", to: "Keisha Grant", toTeam: "Bay Area Living", city: "Tampa, FL", temp: "hot", status: "Fee received", tone: "success", updated: "Oct 19", fee: "$2,880 paid" },
  { id: 4733, lead: "Jenna Wilde", to: "Sam Whitaker", toTeam: "Old Pueblo Partners", city: "Tucson, AZ", temp: "future", status: "Nurture", tone: "neutral", updated: "Oct 12", fee: "$1,980 est." },
];

export const monthly = [
  { m: "Jun", sent: 3, received: 2, fees: 2400 }, { m: "Jul", sent: 5, received: 3, fees: 0 }, { m: "Aug", sent: 4, received: 5, fees: 5310 },
  { m: "Sep", sent: 6, received: 4, fees: 2880 }, { m: "Oct", sent: 7, received: 6, fees: 6120 }, { m: "Nov", sent: 4, received: 3, fees: 3210 },
];

export const cityPoints: { city: string; lon: number; lat: number; status: "active" | "open" | "waitlist" | "you" }[] = [
  { city: "St. George, UT", lon: -113.58, lat: 37.1, status: "you" }, { city: "Salt Lake City, UT", lon: -111.89, lat: 40.76, status: "active" },
  { city: "Provo, UT", lon: -111.66, lat: 40.23, status: "active" }, { city: "Boise, ID", lon: -116.2, lat: 43.62, status: "active" },
  { city: "Twin Falls, ID", lon: -114.46, lat: 42.56, status: "open" }, { city: "Denver, CO", lon: -104.99, lat: 39.74, status: "active" },
  { city: "Colorado Springs, CO", lon: -104.82, lat: 38.83, status: "waitlist" }, { city: "Pueblo, CO", lon: -104.61, lat: 38.25, status: "open" },
  { city: "Las Vegas, NV", lon: -115.14, lat: 36.17, status: "active" }, { city: "Reno, NV", lon: -119.81, lat: 39.53, status: "active" },
  { city: "Elko, NV", lon: -115.76, lat: 40.83, status: "open" }, { city: "Billings, MT", lon: -108.5, lat: 45.78, status: "active" },
  { city: "Missoula, MT", lon: -113.99, lat: 46.87, status: "waitlist" }, { city: "Cheyenne, WY", lon: -104.82, lat: 41.14, status: "active" },
  { city: "Grand Junction, CO", lon: -108.55, lat: 39.06, status: "active" }, { city: "Flagstaff, AZ", lon: -111.65, lat: 35.2, status: "active" },
];

export const applicants = [
  { name: "Nora Castillo", team: "Front Range Homes", city: "Pueblo, CO", trust: 87, years: 9, deals: 64, other: "Sun Belt Top Teams", applied: "2 h ago" },
  { name: "Wes Adler", team: "Magic Valley Realty", city: "Twin Falls, ID", trust: "new" as const, years: 4, deals: 22, other: "—", applied: "Yesterday" },
  { name: "Brianna Cole", team: "Peak to Peak Group", city: "Colorado Springs, CO", trust: 92, years: 12, deals: 118, other: "Relocation Pros Network", applied: "3 days ago" },
];

export const members = [
  { team: "Silver State Partners", leader: "Raj Mehta", city: "Reno, NV", breaches: 2, status: "Deactivated", last: "Missed accept deadline, Nov 6", trust: 71 },
  { team: "Big Sky Homes", leader: "Erin Walsh", city: "Billings, MT", breaches: 1, status: "Warned", last: "Missed weekly update, Nov 3", trust: 82 },
  { team: "Wasatch Key Group", leader: "Tyler Moss", city: "Provo, UT", breaches: 0, status: "Active", last: "—", trust: 93 },
  { team: "Mile High Movers", leader: "Carla Diaz", city: "Denver, CO", breaches: 0, status: "Active", last: "—", trust: 95 },
  { team: "Neon Valley Realty", leader: "Andre Silva", city: "Las Vegas, NV", breaches: 1, status: "Warned", last: "Closing report late, Oct 28", trust: 84 },
];
