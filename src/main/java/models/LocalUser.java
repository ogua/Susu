package models;

/**
 * A desktop login account row from local_users. In standalone mode this is
 * the authoritative user record; in hybrid mode it mirrors a server user
 * (server_user_id set) so staff can re-enter while offline.
 */
public class LocalUser {

    private String id;
    private String serverUserId;
    private String name;
    private String email;
    private String phone;
    private String role;
    private boolean active;

    public LocalUser() {}

    public LocalUser(String id, String serverUserId, String name, String email,
                     String phone, String role, boolean active) {
        this.id = id;
        this.serverUserId = serverUserId;
        this.name = name;
        this.email = email;
        this.phone = phone;
        this.role = role;
        this.active = active;
    }

    public String getId() { return id; }
    public void setId(String id) { this.id = id; }

    public String getServerUserId() { return serverUserId; }
    public void setServerUserId(String serverUserId) { this.serverUserId = serverUserId; }

    public String getName() { return name; }
    public void setName(String name) { this.name = name; }

    public String getEmail() { return email; }
    public void setEmail(String email) { this.email = email; }

    public String getPhone() { return phone; }
    public void setPhone(String phone) { this.phone = phone; }

    public String getRole() { return role; }
    public void setRole(String role) { this.role = role; }

    public boolean isActive() { return active; }
    public void setActive(boolean active) { this.active = active; }
}
