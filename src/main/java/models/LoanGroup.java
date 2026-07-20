package models;

import java.util.List;

public class LoanGroup {

    private String id;
    private String createdBy;
    private String name;
    private String code;
    private boolean active;

    /** Populated by detail queries; not always present. */
    private List<LoanGroupMember> members;

    public String getId() { return id; }
    public void setId(String id) { this.id = id; }

    public String getCreatedBy() { return createdBy; }
    public void setCreatedBy(String createdBy) { this.createdBy = createdBy; }

    public String getName() { return name; }
    public void setName(String name) { this.name = name; }

    public String getCode() { return code; }
    public void setCode(String code) { this.code = code; }

    public boolean isActive() { return active; }
    public void setActive(boolean active) { this.active = active; }

    public List<LoanGroupMember> getMembers() { return members; }
    public void setMembers(List<LoanGroupMember> members) { this.members = members; }

    @Override
    public String toString() {
        return name + " (" + code + ")";
    }
}
